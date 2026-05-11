<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\Captcha\CaptchaPolicy;
use App\Auth\ErrorCodes;
use App\Auth\StrategyResolver;
use App\Auth\TestMode\Policy as TestModePolicy;
use App\Http\Resources\ClientResource;
use App\Http\Resources\SignInResource;
use App\Jobs\Mail\SendPasswordChangedNotification;
use App\Models\BackupCode;
use App\Models\Challenge;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\Session;
use App\Models\SignInAttempt;
use App\Models\TotpSecret;
use App\Models\User;
use App\Models\Verification;
use App\Services\Client\ClientResolver;
use App\Services\Sessions\SessionLifecycle;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * FAPI sign-in state-machine controller. The factor verification surface
 * (formerly prepare-/attempt-first-factor and reset-password) is now
 * served by ChallengeController under `/sign-ins/{sid}/challenges`.
 *
 * Endpoints:
 *   POST   /v1/client/sign-ins
 *   GET    /v1/client/sign-ins/{id}
 *   PATCH  /v1/client/sign-ins/{id}              (set new password during reset)
 *
 * The store path keeps a one-shot inline-strategy shortcut for
 * `password` and `ticket` so the SDK can complete a sign-in in one
 * round-trip; under the hood it issues a single Challenge + answer
 * synchronously and exposes `current_challenge_id` on the SignIn shape.
 */
final class SignInController
{
    public function __construct(private readonly StrategyResolver $strategies) {}

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $createdClient = false;
        $client = $this->resolveOrCreateClient($request, $env, $createdClient);

        if ($request->boolean('transfer')) {
            return $this->error(422, ErrorCodes::TRANSFER_NOT_SUPPORTED, 'transfer flow is not supported.', $client);
        }

        $identifier = $request->input('identifier');
        $policy = TestModePolicy::resolve($env, is_string($identifier) ? $identifier : null);
        if ($policy === TestModePolicy::STATUS_REJECTED) {
            return $this->error(422, ErrorCodes::TEST_IDENTIFIER_FORBIDDEN, 'Test identifiers are not allowed in this environment.', $client);
        }
        $isTestAttempt = $policy === TestModePolicy::STATUS_TEST;

        $captchaResult = CaptchaPolicy::evaluate($env, $request->input('captcha_token'), is_string($identifier) ? $identifier : null);
        if ($captchaResult === CaptchaPolicy::REASON_MISSING_TOKEN) {
            return $this->error(422, ErrorCodes::CAPTCHA_INVALID, 'A captcha token is required for this request.', $client);
        }

        $strategy = $request->input('strategy');
        if ($strategy !== null && ! in_array($strategy, [
            Verification::STRATEGY_PASSWORD,
            Verification::STRATEGY_EMAIL_CODE,
            Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE,
            Verification::STRATEGY_TICKET,
        ], true)) {
            return $this->error(422, ErrorCodes::STRATEGY_NOT_SUPPORTED, "strategy {$strategy} is not supported.", $client);
        }

        return DB::transaction(function () use ($request, $env, $client, $strategy, $createdClient, $isTestAttempt): JsonResponse {
            $existing = $client->current_sign_in_attempt_id !== null
                ? SignInAttempt::query()->withoutGlobalScopes()->where('id', $client->current_sign_in_attempt_id)->first()
                : null;
            if ($existing !== null && ! in_array($existing->status, [SignInAttempt::STATUS_COMPLETE, SignInAttempt::STATUS_ABANDONED], true) && ! $existing->isAbandoned()) {
                return $this->envelope($client, $existing, 200, null, $createdClient);
            }

            $attempt = SignInAttempt::create([
                'environment_id' => $env->id,
                'client_id' => $client->id,
                'identifier' => $request->input('identifier'),
                'captcha_token' => $request->input('captcha_token'),
                'captcha_widget_type' => $request->input('captcha_widget_type'),
                'captcha_error' => $request->input('captcha_error'),
                'was_test' => $isTestAttempt,
            ]);

            $client->forceFill(['current_sign_in_attempt_id' => $attempt->id])->saveQuietly();

            if ($strategy === Verification::STRATEGY_TICKET) {
                return $this->runOneShot($attempt, $strategy, ['ticket' => $request->input('ticket')], $client, $createdClient);
            }

            if ($strategy === Verification::STRATEGY_PASSWORD && is_string($request->input('password'))) {
                if (! is_string($attempt->identifier)) {
                    return $this->error(422, ErrorCodes::FORM_PARAM_NIL, 'identifier is required when strategy=password.', $client);
                }
                $attempt->status = SignInAttempt::STATUS_NEEDS_FIRST_FACTOR;
                $attempt->save();

                return $this->runOneShot($attempt, $strategy, ['password' => $request->input('password')], $client, $createdClient);
            }

            if (is_string($attempt->identifier)) {
                if ($this->shouldTransferToSignUp($env, $attempt->identifier)) {
                    $attempt->status = SignInAttempt::STATUS_TRANSFERABLE;
                } else {
                    $attempt->status = SignInAttempt::STATUS_NEEDS_FIRST_FACTOR;
                }
                $attempt->save();
            }

            return $this->envelope($client, $attempt->fresh(), 200, null, $createdClient);
        });
    }

    /**
     * Whether a SignIn for `$identifier` should flip to `transferable` so
     * the SDK can bounce to sign-up. True when the identifier does not
     * match any existing User in the env AND the environment's
     * `signup_mode` is open (`public` or `restricted`).
     *
     * Sign-up `restricted` mode still emits a transferable: the SignUp
     * controller will gate the actual creation on the invitation policy.
     */
    private function shouldTransferToSignUp(Environment $env, string $identifier): bool
    {
        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('email_address', strtolower($identifier))
            ->exists();
        if ($email) {
            return false;
        }

        return in_array(
            $env->signup_mode,
            [Environment::SIGNUP_MODE_PUBLIC, Environment::SIGNUP_MODE_RESTRICTED],
            true,
        );
    }

    public function show(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = SignInAttempt::query()
            ->withoutGlobalScopes()
            ->where('id', $sid)
            ->where('client_id', $client->id)
            ->first();
        if ($attempt === null) {
            return $this->error(404, ErrorCodes::SIGN_IN_NOT_FOUND, 'Sign-in attempt not found on this device.', $client);
        }

        return $this->envelope($client, $attempt);
    }

    /**
     * PATCH /v1/client/sign-ins/{sid}
     * Currently the only supported field is `password`, used to set the
     * new password during the reset_password_email_code flow once the
     * SignIn has reached `needs_new_password`.
     */
    public function patch(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        if (! $request->has('password')) {
            return $this->envelope($client, $attempt);
        }

        if ($attempt->status !== SignInAttempt::STATUS_NEEDS_NEW_PASSWORD) {
            return $this->error(422, ErrorCodes::NOT_IN_NEEDS_NEW_PASSWORD_STATE, 'Sign-in is not awaiting a new password.', $client, $attempt);
        }

        $password = $request->input('password');
        if (! is_string($password) || $password === '') {
            return $this->error(422, ErrorCodes::FORM_PARAM_NIL, 'password is required.', $client, $attempt);
        }
        if (strlen($password) < 8) {
            return $this->error(422, ErrorCodes::FORM_PASSWORD_VALIDATION_FAILED, 'Password must be at least 8 characters.', $client, $attempt);
        }

        // Resolve the user via the verified reset-password challenge.
        $challenge = Challenge::query()
            ->withoutGlobalScopes()
            ->where('parent_type', Challenge::PARENT_SIGN_IN)
            ->where('parent_id', $attempt->id)
            ->where('strategy', Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE)
            ->where('status', Challenge::STATUS_VERIFIED)
            ->latest('id')
            ->first();
        $verification = $challenge !== null
            ? Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first()
            : null;
        $email = $verification !== null
            ? EmailAddress::query()->withoutGlobalScopes()->where('id', $verification->verifiable_id)->first()
            : null;
        $user = $email !== null
            ? User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first()
            : null;
        if ($user === null) {
            return $this->error(422, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'User not found.', $client, $attempt);
        }

        $user->setPassword($password);
        $user->save();

        SendPasswordChangedNotification::dispatch($user->id);

        if ($request->boolean('sign_out_of_other_sessions')) {
            $user->sessions()->whereIn('status', Session::LIVE_STATUSES)->each(function (Session $existing): void {
                $existing->forceFill(['status' => Session::STATUS_REVOKED])->save();
            });
        }

        $session = $this->createSession($attempt, $user);

        return $this->envelope($client, $attempt->fresh(), 200, $session);
    }

    /* -------------------- helpers -------------------- */

    private function runOneShot(
        SignInAttempt $attempt,
        string $strategyName,
        array $params,
        Client $client,
        bool $attachClientCookie,
    ): JsonResponse {
        $strategy = $this->strategies->resolve($strategyName);

        // Issue a Verification stub so the Challenge has a wrapped row even
        // for one-shot strategies (password / ticket).
        $verification = Verification::query()->withoutGlobalScopes()->create([
            'environment_id' => $attempt->environment_id,
            'verifiable_type' => $attempt->getMorphClass(),
            'verifiable_id' => $attempt->id,
            'strategy' => $strategyName,
            'status' => Verification::STATUS_UNVERIFIED,
            'attempts' => 0,
            'expire_at' => now()->addMinutes(10),
        ]);

        $challenge = Challenge::query()->withoutGlobalScopes()->create([
            'environment_id' => $attempt->environment_id,
            'parent_type' => Challenge::PARENT_SIGN_IN,
            'parent_id' => $attempt->id,
            'step' => Challenge::STEP_FIRST,
            'strategy' => $strategyName,
            'status' => Challenge::STATUS_PENDING,
            'verification_id' => $verification->id,
            'attempts' => 0,
            'expire_at' => $verification->expire_at,
        ]);
        $attempt->forceFill(['current_challenge_id' => $challenge->id])->save();

        $params['verification'] = $verification;
        $result = $strategy->attempt($attempt, $params);

        if (! $result->success) {
            $challenge->forceFill([
                'status' => Challenge::STATUS_FAILED,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ])->save();

            return $this->error($result->httpStatus, $result->errorCode ?? ErrorCodes::VERIFICATION_FAILED, $result->errorMessage ?? '', $client, $result->attempt);
        }

        $challenge->forceFill([
            'status' => Challenge::STATUS_VERIFIED,
            'attempts' => (int) ($result->verification?->attempts ?? 0),
        ])->save();

        if ($result->user !== null && $this->shouldPivotToSecondFactor($result->attempt, $result->user)) {
            $result->attempt->forceFill([
                'status' => SignInAttempt::STATUS_NEEDS_SECOND_FACTOR,
                'current_challenge_id' => null,
            ])->save();

            return $this->envelope($client, $result->attempt->fresh(), 200, null, $attachClientCookie);
        }

        $session = $this->createSession($result->attempt, $result->user);

        return $this->envelope($client, $result->attempt->fresh(), 200, $session, $attachClientCookie);
    }

    private function shouldPivotToSecondFactor(SignInAttempt $attempt, User $user): bool
    {
        // Strict semantic: pivot when the user is enrolled, regardless
        // of the env-level multi_factor toggle. The toggle gates new
        // enrolments only; an operator who turns it off after users
        // have enrolled MUST clear their rows via BAPI
        // `DELETE /v1/users/{id}/mfa` to actually downgrade them.
        $hasTotp = (bool) $user->totp_enabled && TotpSecret::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->exists();
        if ($hasTotp) {
            return true;
        }

        $hasBackupCodes = (bool) $user->backup_code_enabled && BackupCode::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->exists();
        if ($hasBackupCodes) {
            return true;
        }

        return PhoneNumber::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->where('reserved_for_second_factor', true)
            ->exists();
    }

    private function createSession(SignInAttempt $attempt, ?User $user): Session
    {
        if ($user === null) {
            throw new \InvalidArgumentException('createSession requires a user.');
        }
        $session = Session::create([
            'environment_id' => $attempt->environment_id,
            'client_id' => $attempt->client_id,
            'user_id' => $user->id,
            'status' => Session::STATUS_ACTIVE,
            'was_test' => (bool) $attempt->was_test,
        ]);

        $attempt->forceFill([
            'created_session_id' => $session->id,
            'status' => SignInAttempt::STATUS_COMPLETE,
        ])->save();

        Client::query()
            ->withoutGlobalScopes()
            ->where('id', $attempt->client_id)
            ->update(['last_active_session_id' => $session->id, 'last_active_at' => now()]);

        $user->forceFill(['last_sign_in_at' => now(), 'last_active_at' => now()])->saveQuietly();

        $client = Client::query()->withoutGlobalScopes()->where('id', $attempt->client_id)->first();
        if ($client !== null) {
            app(SessionLifecycle::class)
                ->enforceMultiSessionPolicy(app(Environment::class), $client, $session);
        }

        app(SessionLifecycle::class)->notifyCreated($session->fresh() ?? $session);

        return $session;
    }

    private function loadAttempt(string $sid, Client $client): SignInAttempt|JsonResponse
    {
        $attempt = SignInAttempt::query()
            ->withoutGlobalScopes()
            ->where('id', $sid)
            ->where('client_id', $client->id)
            ->first();
        if ($attempt === null) {
            return $this->error(404, ErrorCodes::SIGN_IN_NOT_FOUND, 'Sign-in attempt not found on this device.', $client);
        }
        if ($attempt->isAbandoned()) {
            return $this->error(422, ErrorCodes::SIGN_IN_ABANDONED, 'This sign-in attempt has been abandoned.', $client, $attempt);
        }
        if ($attempt->status === SignInAttempt::STATUS_COMPLETE) {
            return $this->error(422, ErrorCodes::SIGN_IN_ALREADY_COMPLETE, 'This sign-in attempt is already complete.', $client, $attempt);
        }

        return $attempt;
    }

    private function resolveOrCreateClient(Request $request, Environment $env, bool &$created = false): Client
    {
        $cookie = $request->cookie('__client');
        if (is_string($cookie)) {
            $client = app(ClientResolver::class)->fromCookie($cookie, $env);
            if ($client !== null) {
                return $client;
            }
        }

        $created = true;

        return Client::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'last_active_at' => now(),
        ]);
    }

    private function envelope(
        Client $client,
        ?SignInAttempt $attempt,
        int $status = 200,
        ?Session $newSession = null,
        bool $attachClientCookie = false,
    ): JsonResponse {
        $response = response()->json([
            'response' => SignInResource::from($attempt),
            'client' => ClientResource::from($client->fresh()),
        ], $status)->header('Cache-Control', 'no-store');

        if ($attachClientCookie) {
            $response = $response->withCookie($this->buildClientCookie($client));
        }

        if ($newSession !== null) {
            $response = $response->withCookie($this->buildSessionCookie($newSession));
        }

        return $response;
    }

    /**
     * Mints a `__session` JWT for the freshly-issued session and stuffs it
     * into an HttpOnly cookie so top-level Dashboard navigations carry the
     * operator's session without the SDK having to inject Authorization
     * headers. Tenants doing pure cross-origin Bearer-only auth can safely
     * ignore the cookie — they read the JWT from the response body.
     */
    private const SESSION_COOKIE_TTL_SECONDS = 86400;

    private function buildSessionCookie(Session $newSession): Cookie
    {
        $minted = app(SessionTokenIssuer::class)->mint($newSession, lifetimeOverride: self::SESSION_COOKIE_TTL_SECONDS);

        return \Illuminate\Support\Facades\Cookie::make(
            name: '__session',
            value: (string) $minted['jwt'],
            minutes: (int) ceil(self::SESSION_COOKIE_TTL_SECONDS / 60),
            path: '/',
            domain: null,
            secure: request()->secure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    private function buildClientCookie(Client $client): Cookie
    {
        $value = app(ClientResolver::class)->mintCookieValue($client);

        return \Illuminate\Support\Facades\Cookie::make(
            name: '__client',
            value: $value,
            minutes: 60 * 24 * 365,
            path: '/',
            domain: null,
            secure: request()->secure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    private function error(int $status, string $code, string $message, ?Client $client = null, ?SignInAttempt $attempt = null): JsonResponse
    {
        $body = [
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ];
        if ($client !== null) {
            $body['client'] = ClientResource::from($client->fresh());
        }
        if ($attempt !== null) {
            $body['response'] = SignInResource::from($attempt->fresh());
        }

        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
