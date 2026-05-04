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
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Session;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Services\Client\ClientResolver;
use App\Services\Sessions\SessionLifecycle;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * FAPI sign-in state-machine controller.
 *
 * Endpoints:
 *   POST   /v1/client/sign_ins
 *   GET    /v1/client/sign_ins/{id}
 *   POST   /v1/client/sign_ins/{id}/prepare_first_factor
 *   POST   /v1/client/sign_ins/{id}/attempt_first_factor
 *   POST   /v1/client/sign_ins/{id}/prepare_second_factor   (404 in v0.1)
 *   POST   /v1/client/sign_ins/{id}/attempt_second_factor   (404 in v0.1)
 *   POST   /v1/client/sign_ins/{id}/reset_password
 *
 * v0.1 strategies: password, email_code, reset_password_email_code, ticket.
 * Captcha trio is captured but not enforced (AU-18). MFA, OAuth, SAML,
 * passkey strategies are out of scope for v0.1.
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
            return $this->error(422, ErrorCodes::TRANSFER_NOT_SUPPORTED_IN_V0_1, 'transfer flow lands in a later milestone.', $client);
        }

        // Test-mode gate: in `production` envs (default test_mode=rejected),
        // a reserved test identifier returns 422 without creating any state.
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
            return $this->error(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, "strategy {$strategy} is not enabled in v0.1.", $client);
        }

        return DB::transaction(function () use ($request, $env, $client, $strategy, $createdClient, $isTestAttempt): JsonResponse {
            // Idempotency: if the Client already has a non-terminal attempt, return it.
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

            // Inline-strategy short-circuits: when the create call carries a
            // strategy + the credential, run it immediately so the SDK gets
            // the terminal status in one round trip.
            if ($strategy === Verification::STRATEGY_TICKET) {
                return $this->runStrategy($attempt, $strategy, ['ticket' => $request->input('ticket')], $client, terminalAdvance: true, attachClientCookie: $createdClient);
            }

            if ($strategy === Verification::STRATEGY_PASSWORD && is_string($request->input('password'))) {
                if (! is_string($attempt->identifier)) {
                    return $this->error(422, ErrorCodes::FORM_PARAM_NIL, 'identifier is required when strategy=password.', $client);
                }
                $attempt->status = SignInAttempt::STATUS_NEEDS_FIRST_FACTOR;
                $attempt->save();

                return $this->runStrategy($attempt, $strategy, ['password' => $request->input('password')], $client, terminalAdvance: true, attachClientCookie: $createdClient);
            }

            if (is_string($attempt->identifier)) {
                $attempt->status = SignInAttempt::STATUS_NEEDS_FIRST_FACTOR;
                $attempt->save();
            }

            return $this->envelope($client, $attempt->fresh(), 200, null, $createdClient);
        });
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

    public function prepareFirstFactor(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $strategyName = (string) $request->input('strategy', '');
        if ($strategyName === '') {
            return $this->error(422, ErrorCodes::FORM_PARAM_NIL, 'strategy is required.', $client);
        }

        try {
            $strategy = $this->strategies->resolve($strategyName);
        } catch (InvalidArgumentException) {
            return $this->error(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, "strategy {$strategyName} is not enabled in v0.1.", $client);
        }

        if (! $strategy->requiresPrepare()) {
            return $this->error(422, ErrorCodes::PREPARE_NOT_REQUIRED, "{$strategyName} does not need a prepare step.", $client);
        }

        $result = $strategy->prepare($attempt, [
            'email_address_id' => $request->input('email_address_id'),
            'redirect_url' => $request->input('redirect_url'),
        ]);

        if (! $result->success) {
            return $this->error($result->httpStatus, $result->errorCode ?? ErrorCodes::VERIFICATION_FAILED, $result->errorMessage ?? '', $client, $result->attempt);
        }

        return $this->envelope($client, $result->attempt->fresh());
    }

    public function attemptFirstFactor(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $strategyName = (string) $request->input('strategy', '');
        if ($strategyName === '') {
            return $this->error(422, ErrorCodes::FORM_PARAM_NIL, 'strategy is required.', $client);
        }

        try {
            $strategy = $this->strategies->resolve($strategyName);
        } catch (InvalidArgumentException) {
            return $this->error(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, "strategy {$strategyName} is not enabled in v0.1.", $client);
        }

        return $this->runStrategy($attempt, $strategyName, [
            'password' => $request->input('password'),
            'code' => $request->input('code'),
            'ticket' => $request->input('ticket'),
        ], $client, terminalAdvance: true);
    }

    public function prepareSecondFactor(Request $request): JsonResponse
    {
        return $this->error(404, ErrorCodes::MFA_NOT_ENABLED_IN_V0_1, 'Two-step verification lands in v0.3.');
    }

    public function attemptSecondFactor(Request $request): JsonResponse
    {
        return $this->error(404, ErrorCodes::MFA_NOT_ENABLED_IN_V0_1, 'Two-step verification lands in v0.3.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
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

        $verification = Verification::query()->withoutGlobalScopes()->where('id', (string) $attempt->first_factor_verification_id)->first();
        $email = $verification ? EmailAddress::query()->withoutGlobalScopes()->where('id', $verification->verifiable_id)->first() : null;
        $user = $email ? User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first() : null;
        if ($user === null) {
            return $this->error(422, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'User not found.', $client, $attempt);
        }

        // (HIBP breach check is a stub for v0.1; AU-18 wires the real call.)
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

    private function runStrategy(
        SignInAttempt $attempt,
        string $strategyName,
        array $params,
        Client $client,
        bool $terminalAdvance,
        bool $attachClientCookie = false,
    ): JsonResponse {
        $strategy = $this->strategies->resolve($strategyName);
        $result = $strategy->attempt($attempt, $params);

        if (! $result->success) {
            return $this->error($result->httpStatus, $result->errorCode ?? ErrorCodes::VERIFICATION_FAILED, $result->errorMessage ?? '', $client, $result->attempt);
        }

        $attempt = $result->attempt;
        $user = $result->user;

        if (! $terminalAdvance) {
            return $this->envelope($client, $attempt->fresh(), 200, null, $attachClientCookie);
        }

        // Reset-password strategy: hand-off to the new-password endpoint.
        if ($strategyName === Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE) {
            $attempt->status = SignInAttempt::STATUS_NEEDS_NEW_PASSWORD;
            $attempt->save();

            return $this->envelope($client, $attempt->fresh(), 200, null, $attachClientCookie);
        }

        // Otherwise create a Session and complete.
        $session = $this->createSession($attempt, $user);

        return $this->envelope($client, $attempt->fresh(), 200, $session, $attachClientCookie);
    }

    private function createSession(SignInAttempt $attempt, User $user): Session
    {
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

        // Bump the Client's last_active_session_id.
        Client::query()
            ->withoutGlobalScopes()
            ->where('id', $attempt->client_id)
            ->update(['last_active_session_id' => $session->id, 'last_active_at' => now()]);

        // Stamp the user as recently signed in.
        $user->forceFill(['last_sign_in_at' => now(), 'last_active_at' => now()])->saveQuietly();

        // Apply the env's multi-session policy: in single-session mode, evict
        // every other live session on this client; in multi-session mode,
        // enforce the per-client cap by evicting LRU sessions.
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
     *
     * The cookie JWT outlives the in-memory access token (default 60s)
     * because the browser only re-attaches it on top-level navigations,
     * not on every API call where the SDK can refresh from /tokens. A
     * 24h ceiling is short enough that a stolen cookie expires within a
     * day and long enough that operators don't get bounced mid-shift.
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
