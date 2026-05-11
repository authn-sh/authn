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
use App\Models\BackupCode;
use App\Models\Challenge;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\OauthProvider;
use App\Models\Passkey;
use App\Models\PhoneNumber;
use App\Models\Session;
use App\Models\SignInAttempt;
use App\Models\SignUpAttempt;
use App\Models\TotpSecret;
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
 * Uniform Challenge sub-resource for SignIn / SignUp / EmailAddress
 * verification flows. The same controller serves all parents — the
 * concrete `for*` action picks which parent table to load.
 *
 *   POST   /v1/client/sign-{ins|ups}/{sid}/challenges                — issue
 *   POST   /v1/client/sign-{ins|ups}/{sid}/challenges/{cid}/answer   — submit
 *   GET    /v1/client/sign-{ins|ups}/{sid}/challenges/{cid}          — poll
 *
 *   POST   /v1/me/email-addresses/{eid}/challenges                   — issue
 *   POST   /v1/me/email-addresses/{eid}/challenges/{cid}/answer      — submit
 *   GET    /v1/me/email-addresses/{eid}/challenges/{cid}             — poll
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
            'assertion' => $request->input('assertion'),
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
            return $this->errorWithSignUp(422, ErrorCodes::STRATEGY_NOT_SUPPORTED, "strategy {$strategyName} is not supported on this sign-up.", $client, $attempt);
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

    /* ============================ email-address surface ============================ */

    public function storeForEmailAddress(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->bareError(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot verify emails.', null);
        }
        $email = $this->loadEmailForUser($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }

        $strategyName = (string) $request->input('strategy', '');
        if ($strategyName === '') {
            return $this->bareError(422, ErrorCodes::FORM_PARAM_NIL, 'strategy is required.', null);
        }
        if (! in_array($strategyName, [Verification::STRATEGY_EMAIL_CODE, Verification::STRATEGY_EMAIL_LINK], true)) {
            return $this->bareError(422, ErrorCodes::STRATEGY_NOT_SUPPORTED, "strategy {$strategyName} is not supported on email-address challenges.", null);
        }

        $challenge = $strategyName === Verification::STRATEGY_EMAIL_LINK
            ? $this->issueEmailLinkForEmailAddress($email, $request)
            : $this->issueEmailCodeForEmailAddress($email);

        // Inline answer-on-create — the SDK can pass `code` alongside the
        // create call to skip the second round trip for email_code.
        $code = $request->input('code');
        if ($strategyName === Verification::STRATEGY_EMAIL_CODE && is_string($code) && $code !== '') {
            return $this->runEmailAddressAnswer($email, $challenge, ['code' => $code]);
        }

        return $this->emailAddressEnvelope($challenge->fresh());
    }

    public function answerForEmailAddress(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->bareError(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot verify emails.', null);
        }
        $email = $this->loadEmailForUser($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }
        $challenge = $this->loadChallenge((string) $request->route('cid'), $email, Challenge::PARENT_EMAIL_ADDRESS);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        return $this->runEmailAddressAnswer($email, $challenge, [
            'code' => $request->input('code'),
        ]);
    }

    public function showForEmailAddress(Request $request): JsonResponse
    {
        $email = $this->loadEmailForUser($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }
        $challenge = $this->loadChallenge((string) $request->route('cid'), $email, Challenge::PARENT_EMAIL_ADDRESS);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        $this->refreshChallengeFromVerification($challenge);
        // email_link verifications flip out-of-band via the click handler;
        // mirror that into the EmailAddress.verified_at on poll so the
        // SDK sees the final state without a second round trip.
        $this->reflectEmailLinkRedemption($email, $challenge->fresh() ?? $challenge);

        return response()->json(ChallengeResource::from($challenge->fresh()))
            ->header('Cache-Control', 'no-store');
    }

    private function issueEmailCodeForEmailAddress(EmailAddress $email): Challenge
    {
        $verification = $this->verifications->start(
            $email,
            Verification::STRATEGY_EMAIL_CODE,
            self::SIGN_UP_EMAIL_VERIFICATION_TTL,
        );
        $code = $this->verifications->mintNumericCode(
            $verification,
            VerificationCode::PURPOSE_EMAIL_CODE,
            self::SIGN_UP_EMAIL_VERIFICATION_TTL,
        );

        SendVerificationEmail::dispatch(
            $email->environment_id,
            $email->email_address,
            $code,
            VerificationCode::PURPOSE_EMAIL_CODE,
            $verification->id,
            $email->id,
        );

        return $this->createChallenge(
            $email,
            Challenge::PARENT_EMAIL_ADDRESS,
            Challenge::STEP_SINGLE,
            Verification::STRATEGY_EMAIL_CODE,
            $verification,
        );
    }

    private function issueEmailLinkForEmailAddress(EmailAddress $email, Request $request): Challenge
    {
        $verification = $this->verifications->start(
            $email,
            Verification::STRATEGY_EMAIL_LINK,
            MagicLinkIssuer::TTL_SECONDS,
        );

        $redirectUrl = $request->input('redirect_url');
        $minted = app(MagicLinkIssuer::class)->issue(
            $verification,
            is_string($redirectUrl) && $redirectUrl !== '' ? $redirectUrl : null,
        );

        SendMagicLinkEmail::dispatch(
            $email->environment_id,
            $email->email_address,
            $minted['url'],
            EmailTemplate::SLUG_MAGIC_LINK_SIGN_IN,
            $verification->id,
            $email->id,
        );

        $verification->forceFill(['external_verification_redirect_url' => $minted['url']])->save();

        return $this->createChallenge(
            $email,
            Challenge::PARENT_EMAIL_ADDRESS,
            Challenge::STEP_SINGLE,
            Verification::STRATEGY_EMAIL_LINK,
            $verification->fresh() ?? $verification,
        );
    }

    private function runEmailAddressAnswer(EmailAddress $email, Challenge $challenge, array $params): JsonResponse
    {
        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return $this->bareError(422, ErrorCodes::VERIFICATION_FAILED, 'Underlying verification missing.', null);
        }

        if ($challenge->strategy === Verification::STRATEGY_EMAIL_LINK) {
            // Empty-body poll. The click handler flips Verification.status
            // out-of-band; here we just mirror it onto the Challenge.
            $this->refreshChallengeFromVerification($challenge);
            $fresh = $challenge->fresh() ?? $challenge;
            if ($fresh->status === Challenge::STATUS_PENDING) {
                return $this->bareError(422, ErrorCodes::VERIFICATION_FAILED, 'Magic link not yet redeemed.', null);
            }
            if ($fresh->status === Challenge::STATUS_EXPIRED) {
                return $this->bareError(422, ErrorCodes::VERIFICATION_EXPIRED, 'Magic link expired.', null);
            }
            if ($fresh->status !== Challenge::STATUS_VERIFIED) {
                return $this->bareError(422, ErrorCodes::VERIFICATION_FAILED, "Challenge in status {$fresh->status}.", null);
            }
            $this->reflectEmailLinkRedemption($email, $fresh);

            return $this->emailAddressEnvelope($fresh);
        }

        $code = $params['code'] ?? null;
        if (! is_string($code) || $code === '') {
            return $this->bareError(422, ErrorCodes::FORM_PARAM_NIL, 'code is required.', null);
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

            return $this->bareError(422, $errCode, 'Incorrect code.', null);
        }

        $this->markChallengeVerified($challenge, $verification->fresh() ?? $verification);
        $email->forceFill(['verified_at' => now()])->save();

        return $this->emailAddressEnvelope($challenge->fresh());
    }

    private function reflectEmailLinkRedemption(EmailAddress $email, Challenge $challenge): void
    {
        if ($challenge->status !== Challenge::STATUS_VERIFIED) {
            return;
        }
        if ($email->verified_at !== null) {
            return;
        }
        $email->forceFill(['verified_at' => now()])->save();
    }

    private function emailAddressEnvelope(?Challenge $challenge): JsonResponse
    {
        $client = app()->bound(Client::class) ? app(Client::class) : null;

        return response()->json([
            'response' => ChallengeResource::from($challenge),
            'client' => $client !== null ? ClientResource::from($client->fresh()) : null,
        ])->header('Cache-Control', 'no-store');
    }

    private function loadEmailForUser(Request $request): EmailAddress|JsonResponse
    {
        $eid = (string) $request->route('email_address_id');
        $user = app(User::class);
        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('id', $eid)
            ->where('user_id', $user->id)
            ->first();
        if ($email === null) {
            return $this->bareError(404, ErrorCodes::EMAIL_NOT_FOUND, 'Email not found on this user.', null);
        }

        return $email;
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
            SignInAttempt::STATUS_NEEDS_FIRST_FACTOR => array_merge(
                [
                    Verification::STRATEGY_PASSWORD,
                    Verification::STRATEGY_EMAIL_CODE,
                    Verification::STRATEGY_EMAIL_LINK,
                    Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE,
                    Verification::STRATEGY_TICKET,
                ],
                $this->enabledOauthStrategies($attempt->environment_id, allowSignIn: true),
                $this->signInPasskeyStrategies($attempt),
            ),
            SignInAttempt::STATUS_NEEDS_SECOND_FACTOR => $this->signInSecondFactorStrategies($attempt),
            default => [],
        };
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
        if ($attempt->identifier === null) {
            return [];
        }
        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('email_address', strtolower((string) $attempt->identifier))
            ->first();
        if ($email === null) {
            return [];
        }
        $hasPasskey = Passkey::query()
            ->where('user_id', $email->user_id)
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
