<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Auth\SignUp\StageRequirements;
use App\Auth\StrategyResolver;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\SignInResource;
use App\Http\Resources\SignUpResource;
use App\Jobs\Mail\SendMagicLinkEmail;
use App\Jobs\Mail\SendVerificationEmail;
use App\Models\Challenge;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\Session;
use App\Models\SignInAttempt;
use App\Models\SignUpAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Services\Domains\DomainEnroller;
use App\Services\MagicLink\MagicLinkIssuer;
use App\Services\Sessions\SessionLifecycle;
use App\Services\Sessions\SessionTokenIssuer;
use App\Services\Verification\VerificationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Uniform Challenge sub-resource for SignIn / SignUp factor flows.
 *
 * Replaces the per-factor `prepare-*` / `attempt-*` endpoint pairs with
 * a CRUD-shaped Challenge resource:
 *   POST   /v1/client/sign-{ins|ups}/{sid}/challenges        — issue
 *   POST   /v1/client/sign-{ins|ups}/{sid}/challenges/{cid}/answer — submit
 *   GET    /v1/client/sign-{ins|ups}/{sid}/challenges/{cid}  — poll
 *
 * Each Challenge wraps an underlying Verification 1:1 — the Verification
 * still owns the cryptographic state (codes, attempt counter, expiry);
 * the Challenge is the API-facing veneer that exposes the lifecycle in a
 * shape that's identical across strategies.
 */
final class ChallengeController
{
    private const SIGN_UP_EMAIL_VERIFICATION_TTL = 600;

    private const SESSION_COOKIE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly StrategyResolver $strategies,
        private readonly VerificationManager $verifications,
    ) {}

    /* ============================ sign-in surface ============================ */

    public function storeForSignIn(Request $request): JsonResponse
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
            return $this->errorWithSignIn(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, "strategy {$strategyName} is not supported on this sign-in.", $client, $attempt);
        }

        try {
            $strategy = $this->strategies->resolve($strategyName);
        } catch (InvalidArgumentException) {
            return $this->errorWithSignIn(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, "strategy {$strategyName} is not enabled in v0.1.", $client, $attempt);
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
                || ($strategyName === Verification::STRATEGY_TICKET && is_string($request->input('ticket')));
            if ($hasCredential) {
                return $this->runSignInAnswer($attempt, $challenge, $verification, [
                    'password' => $request->input('password'),
                    'ticket' => $request->input('ticket'),
                ], $client);
            }

            return $this->signInEnvelope($client, $attempt->fresh(), $challenge);
        }

        $result = $strategy->prepare($attempt, [
            'email_address_id' => $request->input('email_address_id'),
            'redirect_url' => $request->input('redirect_url'),
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

    public function answerForSignIn(Request $request): JsonResponse
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
        ], $client);
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

        $session = $this->createSessionForSignIn($attempt, $user);

        return $this->signInEnvelope($client, $attempt->fresh(), $challenge->fresh(), $session);
    }

    public function showForSignIn(Request $request): JsonResponse
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

    /* ============================ sign-up surface ============================ */

    public function storeForSignUp(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadSignUpAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $strategyName = (string) $request->input('strategy', '');
        if ($strategyName === '') {
            return $this->errorWithSignUp(422, ErrorCodes::FORM_PARAM_NIL, 'strategy is required.', $client, $attempt);
        }
        if (! in_array($strategyName, $this->signUpSupportedStrategies($attempt), true)) {
            return $this->errorWithSignUp(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, "strategy {$strategyName} is not supported on this sign-up.", $client, $attempt);
        }
        if (! is_string($attempt->email_address) || $attempt->email_address === '') {
            return $this->errorWithSignUp(422, ErrorCodes::FORM_PARAM_NIL, 'email_address is required before issuing a challenge.', $client, $attempt);
        }

        if ($strategyName === Verification::STRATEGY_EMAIL_LINK) {
            return $this->issueSignUpEmailLink($client, $attempt, $request);
        }

        $verification = $this->verifications->start($attempt, Verification::STRATEGY_EMAIL_CODE, self::SIGN_UP_EMAIL_VERIFICATION_TTL);
        $code = $this->verifications->mintNumericCode($verification, VerificationCode::PURPOSE_EMAIL_CODE, self::SIGN_UP_EMAIL_VERIFICATION_TTL);

        SendVerificationEmail::dispatch(
            $attempt->environment_id,
            $attempt->email_address,
            $code,
            VerificationCode::PURPOSE_EMAIL_CODE,
            $verification->id,
        );

        $challenge = $this->createChallenge($attempt, Challenge::PARENT_SIGN_UP, Challenge::STEP_SINGLE, Verification::STRATEGY_EMAIL_CODE, $verification);

        return $this->signUpEnvelope($client, $attempt->fresh(), $challenge);
    }

    public function answerForSignUp(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $cid = (string) $request->route('cid');
        $client = app(Client::class);

        $attempt = $this->loadSignUpAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $challenge = $this->loadChallenge($cid, $attempt, Challenge::PARENT_SIGN_UP);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return $this->errorWithSignUp(422, ErrorCodes::VERIFICATION_FAILED, 'Underlying verification missing.', $client, $attempt);
        }

        if ($challenge->strategy === Verification::STRATEGY_EMAIL_LINK) {
            // Empty body — commits the SDK to polling; the click flips the
            // Verification out-of-band.
            $this->refreshChallengeFromVerification($challenge);
            $fresh = $challenge->fresh();
            if ($fresh === null) {
                return $this->errorWithSignUp(422, ErrorCodes::VERIFICATION_FAILED, 'Challenge missing.', $client, $attempt);
            }
            if ($fresh->status === Challenge::STATUS_PENDING) {
                return $this->errorWithSignUp(422, ErrorCodes::VERIFICATION_FAILED, 'Magic link not yet redeemed.', $client, $attempt, $fresh);
            }
            if ($fresh->status === Challenge::STATUS_EXPIRED) {
                return $this->errorWithSignUp(422, ErrorCodes::VERIFICATION_EXPIRED, 'Magic link expired.', $client, $attempt, $fresh);
            }
            if ($fresh->status !== Challenge::STATUS_VERIFIED) {
                return $this->errorWithSignUp(422, ErrorCodes::VERIFICATION_FAILED, "Challenge in status {$fresh->status}.", $client, $attempt, $fresh);
            }

            return $this->finalizeSignUpIfReady($client, $attempt, $fresh);
        }

        $code = $request->input('code');
        if (! is_string($code) || $code === '') {
            return $this->errorWithSignUp(422, ErrorCodes::FORM_PARAM_NIL, 'code is required.', $client, $attempt, $challenge);
        }

        $ok = $this->verifications->attempt($verification, $code);
        if (! $ok) {
            $fresh = $verification->fresh();
            $errCode = $fresh->status === Verification::STATUS_FAILED
                ? ErrorCodes::VERIFICATION_FAILED
                : ($fresh->status === Verification::STATUS_EXPIRED
                    ? ErrorCodes::VERIFICATION_EXPIRED
                    : ErrorCodes::FORM_CODE_INCORRECT);
            $this->reflectFailure($challenge, $fresh, $errCode, 'Incorrect code.');

            return $this->errorWithSignUp(422, $errCode, 'Incorrect code.', $client, $attempt, $challenge->fresh());
        }

        $this->markChallengeVerified($challenge, $verification->fresh() ?? $verification);

        return $this->finalizeSignUpIfReady($client, $attempt, $challenge->fresh());
    }

    public function showForSignUp(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $cid = (string) $request->route('cid');
        $client = app(Client::class);

        $attempt = $this->loadSignUpAttempt($sid, $client, allowTerminal: true);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $challenge = $this->loadChallenge($cid, $attempt, Challenge::PARENT_SIGN_UP);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        $this->refreshChallengeFromVerification($challenge);

        return response()->json(ChallengeResource::from($challenge->fresh()))
            ->header('Cache-Control', 'no-store');
    }

    /* ================================ helpers ================================ */

    private function issueSignUpEmailLink(Client $client, SignUpAttempt $attempt, Request $request): JsonResponse
    {
        $verification = $this->verifications->start(
            $attempt,
            Verification::STRATEGY_EMAIL_LINK,
            MagicLinkIssuer::TTL_SECONDS,
        );
        $redirectUrl = $request->input('redirect_url');
        $minted = app(MagicLinkIssuer::class)->issue(
            $verification,
            is_string($redirectUrl) && $redirectUrl !== '' ? $redirectUrl : null,
        );

        SendMagicLinkEmail::dispatch(
            $attempt->environment_id,
            (string) $attempt->email_address,
            $minted['url'],
            EmailTemplate::SLUG_MAGIC_LINK_SIGN_UP,
            $verification->id,
        );

        $verification->forceFill(['external_verification_redirect_url' => $minted['url']])->save();

        $challenge = $this->createChallenge(
            $attempt,
            Challenge::PARENT_SIGN_UP,
            Challenge::STEP_SINGLE,
            Verification::STRATEGY_EMAIL_LINK,
            $verification->fresh() ?? $verification,
        );

        return $this->signUpEnvelope($client, $attempt->fresh(), $challenge);
    }

    private function createChallenge(
        Model $attempt,
        string $parentType,
        string $step,
        string $strategy,
        Verification $verification,
    ): Challenge {
        return DB::transaction(function () use ($attempt, $parentType, $step, $strategy, $verification): Challenge {
            $challenge = Challenge::query()->withoutGlobalScopes()->create([
                'environment_id' => $attempt->environment_id,
                'parent_type' => $parentType,
                'parent_id' => $attempt->getKey(),
                'step' => $step,
                'strategy' => $strategy,
                'status' => $this->mapVerificationStatus($verification->status),
                'verification_id' => $verification->id,
                'attempts' => (int) $verification->attempts,
                'nonce' => $verification->nonce,
                'external_verification_redirect_url' => $verification->external_verification_redirect_url,
                'error_code' => $verification->error_code,
                'error_message' => $verification->error_message,
                'expire_at' => $verification->expire_at,
            ]);

            $attempt->forceFill(['current_challenge_id' => $challenge->id])->save();

            return $challenge;
        });
    }

    private function refreshChallengeFromVerification(Challenge $challenge): void
    {
        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return;
        }
        $challenge->forceFill([
            'status' => $this->mapVerificationStatus($verification->status),
            'attempts' => (int) $verification->attempts,
            'nonce' => $verification->nonce,
            'external_verification_redirect_url' => $verification->external_verification_redirect_url,
            'error_code' => $verification->error_code,
            'error_message' => $verification->error_message,
        ])->save();
    }

    private function reflectFailure(Challenge $challenge, ?Verification $verification, ?string $errorCode, ?string $errorMessage): void
    {
        $update = [
            'attempts' => $verification !== null ? (int) $verification->attempts : (int) $challenge->attempts + 1,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
        if ($verification !== null) {
            $update['status'] = $this->mapVerificationStatus($verification->status);
        }
        $challenge->forceFill($update)->save();

        if ($verification !== null && $verification->status !== Verification::STATUS_UNVERIFIED) {
            $this->clearParentCurrentChallenge($challenge);
        }
    }

    private function markChallengeVerified(Challenge $challenge, Verification $verification): void
    {
        $challenge->forceFill([
            'status' => Challenge::STATUS_VERIFIED,
            'attempts' => (int) $verification->attempts,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $this->clearParentCurrentChallenge($challenge);
    }

    /**
     * Clears `current_challenge_id` on the parent SignIn / SignUp once
     * the challenge has left `pending`. Keeps the SDK polling story
     * unambiguous: `current_challenge_id` always points at a live
     * challenge worth interacting with, never at a terminal one.
     */
    private function clearParentCurrentChallenge(Challenge $challenge): void
    {
        $parentClass = Challenge::MORPH_MAP[$challenge->parent_type] ?? null;
        if ($parentClass === null) {
            return;
        }
        /** @var class-string<Model> $parentClass */
        $parentClass::query()
            ->withoutGlobalScopes()
            ->where('id', $challenge->parent_id)
            ->where('current_challenge_id', $challenge->id)
            ->update(['current_challenge_id' => null]);
    }

    private function mapVerificationStatus(string $status): string
    {
        return match ($status) {
            Verification::STATUS_UNVERIFIED => Challenge::STATUS_PENDING,
            Verification::STATUS_VERIFIED => Challenge::STATUS_VERIFIED,
            Verification::STATUS_TRANSFERABLE => Challenge::STATUS_TRANSFERABLE,
            Verification::STATUS_FAILED => Challenge::STATUS_FAILED,
            Verification::STATUS_EXPIRED => Challenge::STATUS_EXPIRED,
            default => Challenge::STATUS_PENDING,
        };
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
            SignInAttempt::STATUS_NEEDS_FIRST_FACTOR => [
                Verification::STRATEGY_PASSWORD,
                Verification::STRATEGY_EMAIL_CODE,
                Verification::STRATEGY_EMAIL_LINK,
                Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE,
                Verification::STRATEGY_TICKET,
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function signUpSupportedStrategies(SignUpAttempt $attempt): array
    {
        $unverified = is_array($attempt->unverified_fields) ? $attempt->unverified_fields : [];
        if (! in_array('email_address', $unverified, true)) {
            return [];
        }

        return [
            Verification::STRATEGY_EMAIL_CODE,
            Verification::STRATEGY_EMAIL_LINK,
        ];
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

    private function loadSignUpAttempt(string $sid, Client $client, bool $allowTerminal = false): SignUpAttempt|JsonResponse
    {
        $attempt = SignUpAttempt::query()
            ->withoutGlobalScopes()
            ->where('id', $sid)
            ->where('client_id', $client->id)
            ->first();
        if ($attempt === null) {
            return $this->bareError(404, ErrorCodes::SIGN_UP_NOT_FOUND, 'Sign-up attempt not found on this device.', $client);
        }
        if (! $allowTerminal) {
            if ($attempt->status === SignUpAttempt::STATUS_ABANDONED) {
                return $this->errorWithSignUp(422, ErrorCodes::SIGN_UP_ABANDONED, 'This sign-up attempt has been abandoned.', $client, $attempt);
            }
            if ($attempt->status === SignUpAttempt::STATUS_COMPLETE) {
                return $this->errorWithSignUp(422, ErrorCodes::SIGN_UP_ALREADY_COMPLETE, 'This sign-up attempt is already complete.', $client, $attempt);
            }
        }

        return $attempt;
    }

    private function loadChallenge(string $cid, Model $attempt, string $expectedParentType): Challenge|JsonResponse
    {
        $challenge = Challenge::query()
            ->withoutGlobalScopes()
            ->where('id', $cid)
            ->where('parent_type', $expectedParentType)
            ->where('parent_id', $attempt->getKey())
            ->first();
        if ($challenge === null) {
            return $this->bareError(404, ErrorCodes::VERIFICATION_FAILED, 'Challenge not found.', app(Client::class));
        }

        return $challenge;
    }

    private function finalizeSignUpIfReady(Client $client, SignUpAttempt $attempt, ?Challenge $challenge): JsonResponse
    {
        $env = app(Environment::class);

        $stage = app(StageRequirements::class);

        $verifiedFlags = [];
        $emailVerified = $attempt->challenges()
            ->where('status', Challenge::STATUS_VERIFIED)
            ->whereIn('strategy', [Verification::STRATEGY_EMAIL_CODE, Verification::STRATEGY_EMAIL_LINK])
            ->exists();
        if ($emailVerified) {
            $verifiedFlags['email_address'] = true;
        }

        $supplied = [
            'email_address' => $attempt->email_address,
            'username' => $attempt->username,
            'first_name' => $attempt->first_name,
            'last_name' => $attempt->last_name,
            'password' => $attempt->password_hash !== null ? '__present__' : null,
        ];
        $eval = $stage->evaluate($env, $supplied, $verifiedFlags);

        $attempt->missing_fields = $eval['missing_fields'];
        $attempt->unverified_fields = $eval['unverified_fields'];

        if (! empty($eval['missing_fields']) || ! empty($eval['unverified_fields'])) {
            $attempt->save();

            return $this->signUpEnvelope($client, $attempt->fresh(), $challenge);
        }

        return DB::transaction(function () use ($env, $client, $attempt, $challenge): JsonResponse {
            $user = User::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'first_name' => $attempt->first_name,
                'last_name' => $attempt->last_name,
                'username' => $attempt->username,
                'password_hash' => $attempt->password_hash,
                'password_changed_at' => $attempt->password_hash !== null ? now() : null,
                'public_metadata' => is_array($attempt->public_metadata) ? $attempt->public_metadata : [],
                'unsafe_metadata' => is_array($attempt->unsafe_metadata) ? $attempt->unsafe_metadata : [],
            ]);

            $email = EmailAddress::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'user_id' => $user->id,
                'email_address' => (string) $attempt->email_address,
                'verified_at' => now(),
                'is_primary' => true,
            ]);

            $user->forceFill(['primary_email_address_id' => $email->id])->saveQuietly();

            $session = Session::create([
                'environment_id' => $env->id,
                'client_id' => $client->id,
                'user_id' => $user->id,
                'status' => Session::STATUS_ACTIVE,
                'was_test' => (bool) $attempt->was_test,
            ]);

            $attempt->forceFill([
                'created_user_id' => $user->id,
                'created_session_id' => $session->id,
                'status' => SignUpAttempt::STATUS_COMPLETE,
                'missing_fields' => [],
                'unverified_fields' => [],
            ])->save();

            Client::query()
                ->withoutGlobalScopes()
                ->where('id', $client->id)
                ->update(['last_active_session_id' => $session->id, 'last_active_at' => now()]);

            $reloadedClient = Client::query()->withoutGlobalScopes()->where('id', $client->id)->first();
            if ($reloadedClient !== null) {
                app(SessionLifecycle::class)
                    ->enforceMultiSessionPolicy($env, $reloadedClient, $session);
            }
            app(SessionLifecycle::class)->notifyCreated($session->fresh() ?? $session);

            app(DomainEnroller::class)->enroll($env, $email->fresh());

            return $this->signUpEnvelope($client, $attempt->fresh(), $challenge, $session);
        });
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

    /* --------------------------- response envelopes --------------------------- */

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

    private function signUpEnvelope(
        Client $client,
        ?SignUpAttempt $attempt,
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

    private function errorWithSignUp(int $status, string $code, string $message, ?Client $client, ?SignUpAttempt $attempt = null, ?Challenge $challenge = null): JsonResponse
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
            $body['sign_up'] = SignUpResource::from($attempt->fresh());
        }
        if ($challenge !== null) {
            $body['response'] = ChallengeResource::from($challenge);
        }

        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

    private function bareError(int $status, string $code, string $message, ?Client $client): JsonResponse
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

        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

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
}
