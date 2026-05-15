<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Auth\StrategyResolver;
use App\Http\Controllers\Fapi\Concerns\ManagesChallenges;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\SignInResource;
use App\Models\BackupCode;
use App\Models\Challenge;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\OauthProvider;
use App\Models\Passkey;
use App\Models\PhoneNumber;
use App\Models\Session;
use App\Models\SignInAttempt;
use App\Models\TotpSecret;
use App\Models\User;
use App\Models\Verification;
use App\Services\Sessions\SessionLifecycle;
use App\Services\SignIn\IdentifierResolver;
use App\Settings\SignInMethodsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Challenge sub-resource for SignIn parents.
 *
 *   POST   /v1/client/sign-ins/{sid}/challenges                — issue
 *   POST   /v1/client/sign-ins/{sid}/challenges/{cid}/answer   — submit
 *   GET    /v1/client/sign-ins/{sid}/challenges/{cid}          — poll
 */
final class SignInChallengeController
{
    use ManagesChallenges;

    public function __construct(
        private readonly StrategyResolver $strategies,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadSignInAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $strategyName = (string) $request->input('strategy', '');
        if ($strategyName === '') {
            return $this->errorWithSignIn(422, ErrorCodes::FORM_PARAM_NIL, 'strategy is required.', $client, $attempt);
        }
        if (! in_array($strategyName, $this->signInSupportedStrategies($attempt), true)) {
            return $this->errorWithSignIn(422, ErrorCodes::STRATEGY_NOT_SUPPORTED, "strategy {$strategyName} is not supported on this sign-in.", $client, $attempt);
        }

        try {
            $strategy = $this->strategies->resolve($strategyName);
        } catch (InvalidArgumentException) {
            return $this->errorWithSignIn(422, ErrorCodes::STRATEGY_NOT_SUPPORTED, "strategy {$strategyName} is not supported.", $client, $attempt);
        }

        $step = $this->resolveSignInStep($attempt);

        if (! $strategy->requiresPrepare()) {
            // password / ticket — no out-of-band material to issue. Persist
            // a Verification stub + Challenge wrapper, then if the create
            // call carried the credential (`{strategy: "password", password}`
            // or `{strategy: "ticket", ticket}`), run `attempt` synchronously
            // so the SDK can complete the sign-in in a single round trip.
            $verification = Verification::query()->withoutGlobalScopes()->create([
                'environment_id' => $attempt->environment_id,
                'verifiable_type' => $attempt->getMorphClass(),
                'verifiable_id' => $attempt->id,
                'strategy' => $strategyName,
                'status' => Verification::STATUS_UNVERIFIED,
                'attempts' => 0,
                'expire_at' => now()->addMinutes(10),
            ]);

            $challenge = $this->createChallenge($attempt, Challenge::PARENT_SIGN_IN, $step, $strategyName, $verification);

            $hasCredential = ($strategyName === Verification::STRATEGY_PASSWORD && is_string($request->input('password')))
                || ($strategyName === Verification::STRATEGY_TICKET && is_string($request->input('ticket')))
                || (in_array($strategyName, [Verification::STRATEGY_TOTP, Verification::STRATEGY_BACKUP_CODE], true) && is_string($request->input('code')));
            if ($hasCredential) {
                return $this->runSignInAnswer($attempt, $challenge, $verification, [
                    'password' => $request->input('password'),
                    'ticket' => $request->input('ticket'),
                    'code' => $request->input('code'),
                ], $client);
            }

            return $this->signInEnvelope($client, $attempt->fresh(), $challenge);
        }

        // Passkey: the ceremony needs a live Challenge row so the strategy
        // can stash `request_options` on `metadata` before the response goes
        // out. Pre-create the Verification + Challenge, hand both into
        // prepare, then return the challenge with the ceremony parameters.
        if ($strategyName === Verification::STRATEGY_PASSKEY) {
            $verification = Verification::query()->withoutGlobalScopes()->create([
                'environment_id' => $attempt->environment_id,
                'verifiable_type' => $attempt->getMorphClass(),
                'verifiable_id' => $attempt->id,
                'strategy' => $strategyName,
                'status' => Verification::STATUS_UNVERIFIED,
                'attempts' => 0,
                'expire_at' => now()->addMinutes(10),
            ]);
            $challenge = $this->createChallenge($attempt, Challenge::PARENT_SIGN_IN, $step, $strategyName, $verification);

            $result = $strategy->prepare($attempt, [
                'challenge' => $challenge,
                'verification' => $verification,
            ]);

            if (! $result->success) {
                $challenge->forceFill([
                    'status' => Challenge::STATUS_FAILED,
                    'error_code' => $result->errorCode,
                ])->save();

                return $this->errorWithSignIn(
                    $result->httpStatus,
                    $result->errorCode ?? ErrorCodes::VERIFICATION_FAILED,
                    $result->errorMessage ?? '',
                    $client,
                    $attempt->fresh(),
                    $challenge->fresh(),
                );
            }

            return $this->signInEnvelope($client, $attempt->fresh(), $challenge->fresh());
        }

        $providerKey = preg_match(Verification::OAUTH_STRATEGY_PATTERN, $strategyName) === 1
            ? substr($strategyName, strlen('oauth_'))
            : null;
        $result = $strategy->prepare($attempt, [
            'email_address_id' => $request->input('email_address_id'),
            'redirect_url' => $request->input('redirect_url'),
            'redirect_url_complete' => $request->input('redirect_url_complete'),
            'provider_key' => $providerKey,
            'client' => $client,
        ]);

        if (! $result->success || $result->verification === null) {
            return $this->errorWithSignIn(
                $result->httpStatus,
                $result->errorCode ?? ErrorCodes::VERIFICATION_FAILED,
                $result->errorMessage ?? '',
                $client,
                $attempt->fresh(),
            );
        }

        $challenge = $this->createChallenge($attempt, Challenge::PARENT_SIGN_IN, $step, $strategyName, $result->verification);

        return $this->signInEnvelope($client, $attempt->fresh(), $challenge);
    }

    public function answer(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $cid = (string) $request->route('cid');
        $client = app(Client::class);

        $attempt = $this->loadSignInAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $challenge = $this->loadChallenge($cid, $attempt, Challenge::PARENT_SIGN_IN);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return $this->errorWithSignIn(422, ErrorCodes::VERIFICATION_FAILED, 'Underlying verification missing.', $client, $attempt);
        }

        return $this->runSignInAnswer($attempt, $challenge, $verification, [
            'password' => $request->input('password'),
            'code' => $request->input('code'),
            'ticket' => $request->input('ticket'),
            'assertion' => $request->input('assertion'),
        ], $client);
    }

    public function show(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $cid = (string) $request->route('cid');
        $client = app(Client::class);

        $attempt = $this->loadSignInAttempt($sid, $client, allowTerminal: true);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $challenge = $this->loadChallenge($cid, $attempt, Challenge::PARENT_SIGN_IN);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        $this->refreshChallengeFromVerification($challenge);

        return response()->json(ChallengeResource::from($challenge->fresh()))
            ->header('Cache-Control', 'no-store');
    }

    private function runSignInAnswer(
        SignInAttempt $attempt,
        Challenge $challenge,
        Verification $verification,
        array $params,
        Client $client,
    ): JsonResponse {
        $strategy = $this->strategies->resolve($challenge->strategy);

        $params['verification'] = $verification;
        $params['challenge'] = $challenge;
        $result = $strategy->attempt($attempt, $params);

        if (! $result->success) {
            $this->reflectFailure($challenge, $result->verification ?? $verification, $result->errorCode, $result->errorMessage);

            return $this->errorWithSignIn(
                $result->httpStatus,
                $result->errorCode ?? ErrorCodes::VERIFICATION_FAILED,
                $result->errorMessage ?? '',
                $client,
                $attempt->fresh(),
                $challenge->fresh(),
            );
        }

        $attempt = $result->attempt;
        $user = $result->user;

        $this->markChallengeVerified($challenge, $result->verification ?? $verification);

        // reset_password_email_code: parent transitions to needs_new_password
        // and the SDK follows up with PATCH /sign-ins/{sid} { password }.
        if ($challenge->strategy === Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE) {
            $attempt->status = SignInAttempt::STATUS_NEEDS_NEW_PASSWORD;
            $attempt->save();

            return $this->signInEnvelope($client, $attempt->fresh(), $challenge->fresh());
        }

        // First-factor success on a user with enrolled MFA: pivot to
        // needs_second_factor instead of completing. Second-factor
        // strategies (totp, backup_code) skip this branch — their answer
        // promotes the attempt straight to complete.
        //
        // The pivot is purely a function of user enrolment; the env-level
        // multi_factor toggle gates *new enrolments* (in MeTotpController
        // / MeBackupCodesController) but never bypasses an already-
        // enrolled user's second factor. Operator policy changes don't
        // silently downgrade a user from "I expect MFA" to "first-factor-
        // only".
        if ($challenge->step === Challenge::STEP_FIRST
            && $user !== null
            && $this->userHasEnrolledSecondFactor($user)
        ) {
            $attempt->status = SignInAttempt::STATUS_NEEDS_SECOND_FACTOR;
            $attempt->save();

            return $this->signInEnvelope($client, $attempt->fresh(), $challenge->fresh());
        }

        $session = $this->createSessionForSignIn($attempt, $user);

        return $this->signInEnvelope($client, $attempt->fresh(), $challenge->fresh(), $session);
    }

    private function userHasEnrolledSecondFactor(User $user): bool
    {
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

    private function resolveSignInStep(SignInAttempt $attempt): string
    {
        return match ($attempt->status) {
            SignInAttempt::STATUS_NEEDS_SECOND_FACTOR => Challenge::STEP_SECOND,
            default => Challenge::STEP_FIRST,
        };
    }

    /**
     * @return list<string>
     */
    private function signInSupportedStrategies(SignInAttempt $attempt): array
    {
        return match ($attempt->status) {
            SignInAttempt::STATUS_NEEDS_FIRST_FACTOR => array_merge(
                $this->signInFirstFactorBaseStrategies($attempt),
                $this->enabledOauthStrategies($attempt->environment_id, allowSignIn: true),
                $this->signInPasskeyStrategies($attempt),
            ),
            SignInAttempt::STATUS_NEEDS_SECOND_FACTOR => $this->signInSecondFactorStrategies($attempt),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function signInFirstFactorBaseStrategies(SignInAttempt $attempt): array
    {
        $signInMethods = SignInMethodsSettings::fromUserSettings(
            is_array($attempt->environment?->user_settings) ? $attempt->environment->user_settings : [],
        );
        $identifierType = is_string($attempt->identifier)
            ? IdentifierResolver::detect($attempt->identifier)
            : null;
        $strategies = [
            Verification::STRATEGY_PASSWORD,
            Verification::STRATEGY_TICKET,
        ];
        if ($identifierType === IdentifierResolver::TYPE_EMAIL) {
            if ($signInMethods->emailCodeAllowed()) {
                $strategies[] = Verification::STRATEGY_EMAIL_CODE;
            }
            if ($signInMethods->emailEnabled) {
                $strategies[] = Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE;
            }
        }
        if ($identifierType === IdentifierResolver::TYPE_PHONE && $signInMethods->phoneEnabled) {
            $strategies[] = Verification::STRATEGY_PHONE_CODE;
        }

        return $strategies;
    }

    /**
     * `passkey` shows up in `supported_strategies` only when the resolved
     * user holds at least one verified passkey — strict-semantic per AU-13
     * (toggle gates enrolment, not enforcement of existing credentials).
     *
     * @return list<string>
     */
    private function signInPasskeyStrategies(SignInAttempt $attempt): array
    {
        if (! is_string($attempt->identifier) || $attempt->environment === null) {
            return [];
        }
        $user = IdentifierResolver::resolve($attempt->environment, $attempt->identifier);
        if ($user === null) {
            return [];
        }
        $hasPasskey = Passkey::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->exists();

        return $hasPasskey ? [Verification::STRATEGY_PASSKEY] : [];
    }

    /**
     * @return list<string>
     */
    private function enabledOauthStrategies(string $environmentId, bool $allowSignIn = false, bool $allowSignUp = false): array
    {
        $query = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $environmentId)
            ->where('enabled', true);
        if ($allowSignIn) {
            $query->where('allow_sign_in', true);
        }
        if ($allowSignUp) {
            $query->where('allow_sign_up', true);
        }

        return $query->pluck('provider_key')
            ->map(fn (string $k): string => 'oauth_'.$k)
            ->values()->all();
    }

    /**
     * Narrow second-factor strategies purely by per-user enrolment.
     * The env-level `multi_factor.{totp,backup_codes}.enabled` toggle
     * gates *new enrolments* (in MeTotpController / MeBackupCodesController)
     * but does not strip already-enrolled users of their second factor.
     * An operator who turns the toggle off after users have enrolled
     * MUST clear those rows (BAPI `DELETE /v1/users/{id}/mfa`) to
     * actually downgrade them.
     *
     * @return list<string>
     */
    private function signInSecondFactorStrategies(SignInAttempt $attempt): array
    {
        $user = $this->resolveUserForSignIn($attempt);
        if ($user === null) {
            return [];
        }

        $strategies = [];
        $hasTotp = (bool) $user->totp_enabled && TotpSecret::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->exists();
        if ($hasTotp) {
            $strategies[] = Verification::STRATEGY_TOTP;
        }
        $hasUnspent = (bool) $user->backup_code_enabled && BackupCode::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->exists();
        if ($hasUnspent) {
            $strategies[] = Verification::STRATEGY_BACKUP_CODE;
        }

        $hasReservedPhone = PhoneNumber::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->where('reserved_for_second_factor', true)
            ->exists();
        if ($hasReservedPhone) {
            $strategies[] = Verification::STRATEGY_PHONE_CODE;
        }

        return $strategies;
    }

    private function resolveUserForSignIn(SignInAttempt $attempt): ?User
    {
        if ($attempt->identifier === null) {
            return null;
        }
        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('email_address', strtolower((string) $attempt->identifier))
            ->first();
        if ($email === null) {
            return null;
        }

        return User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first();
    }

    private function loadSignInAttempt(string $sid, Client $client, bool $allowTerminal = false): SignInAttempt|JsonResponse
    {
        $attempt = SignInAttempt::query()
            ->withoutGlobalScopes()
            ->where('id', $sid)
            ->where('client_id', $client->id)
            ->first();
        if ($attempt === null) {
            return $this->bareError(404, ErrorCodes::SIGN_IN_NOT_FOUND, 'Sign-in attempt not found on this device.', $client);
        }
        if (! $allowTerminal) {
            if ($attempt->isAbandoned()) {
                return $this->errorWithSignIn(422, ErrorCodes::SIGN_IN_ABANDONED, 'This sign-in attempt has been abandoned.', $client, $attempt);
            }
            if ($attempt->status === SignInAttempt::STATUS_COMPLETE) {
                return $this->errorWithSignIn(422, ErrorCodes::SIGN_IN_ALREADY_COMPLETE, 'This sign-in attempt is already complete.', $client, $attempt);
            }
        }

        return $attempt;
    }

    private function createSessionForSignIn(SignInAttempt $attempt, ?User $user): Session
    {
        if ($user === null) {
            throw new InvalidArgumentException('createSessionForSignIn requires a user.');
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

    private function signInEnvelope(
        Client $client,
        ?SignInAttempt $attempt,
        ?Challenge $challenge,
        ?Session $newSession = null,
    ): JsonResponse {
        $response = response()->json([
            'response' => ChallengeResource::from($challenge),
            'client' => ClientResource::from($client->fresh()),
        ], 200)->header('Cache-Control', 'no-store');

        if ($newSession !== null) {
            $response = $response->withCookie($this->buildSessionCookie($newSession));
        }

        return $response;
    }

    private function errorWithSignIn(int $status, string $code, string $message, ?Client $client, ?SignInAttempt $attempt = null, ?Challenge $challenge = null): JsonResponse
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
            $body['sign_in'] = SignInResource::from($attempt->fresh());
        }
        if ($challenge !== null) {
            $body['response'] = ChallengeResource::from($challenge);
        }

        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
