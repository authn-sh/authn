<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\ErrorCodes;
use App\Jobs\Mail\SendVerificationEmail;
use App\Models\EmailAddress;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Services\Verification\VerificationManager;

/**
 * Email-OTP first-factor for sign-in.
 *
 * prepare:
 *   - Resolves the EmailAddress by identifier (or by emailAddressId from
 *     the SDK).
 *   - Starts a Verification (strategy=email_code, TTL 10min).
 *   - Mints a 6-digit code, persists its sha256, dispatches
 *     SendVerificationEmail.
 *   - Updates `attempt->first_factor_verification_id`.
 *
 * attempt:
 *   - Reads the in-flight Verification.
 *   - Calls VerificationManager::attempt with the user-supplied code.
 *   - On success, returns the User; status flow handled by the
 *     controller.
 */
final class EmailCodeStrategy implements Strategy
{
    public const PURPOSE = VerificationCode::PURPOSE_EMAIL_CODE;

    public const TTL_SECONDS = 600;

    public function __construct(private readonly VerificationManager $verifications) {}

    public function name(): string
    {
        return Verification::STRATEGY_EMAIL_CODE;
    }

    public function requiresPrepare(): bool
    {
        return true;
    }

    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        $emailAddress = $this->resolveEmailAddress($attempt, $params);
        if ($emailAddress === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'No email address matches this identifier.', 422);
        }

        $verification = $this->verifications->start(
            $emailAddress,
            Verification::STRATEGY_EMAIL_CODE,
            self::TTL_SECONDS,
        );
        $code = $this->verifications->mintNumericCode($verification, self::PURPOSE, self::TTL_SECONDS);

        SendVerificationEmail::dispatch(
            $attempt->environment_id,
            $emailAddress->email_address,
            $code,
            self::PURPOSE,
        );

        $attempt->forceFill(['first_factor_verification_id' => $verification->id])->save();

        return StrategyResult::ok($attempt);
    }

    public function attempt(SignInAttempt $attempt, array $params): StrategyResult
    {
        $code = $params['code'] ?? null;
        if (! is_string($code) || $code === '') {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_PARAM_NIL, 'code is required.');
        }

        $verification = Verification::query()
            ->withoutGlobalScopes()
            ->where('id', (string) $attempt->first_factor_verification_id)
            ->first();
        if ($verification === null) {
            return StrategyResult::fail($attempt, ErrorCodes::VERIFICATION_FAILED, 'No verification is in progress for this attempt.', 422);
        }

        $ok = $this->verifications->attempt($verification, $code);
        if (! $ok) {
            $code = $verification->fresh()->status === Verification::STATUS_FAILED
                ? ErrorCodes::VERIFICATION_FAILED
                : ($verification->fresh()->status === Verification::STATUS_EXPIRED
                    ? ErrorCodes::VERIFICATION_EXPIRED
                    : ErrorCodes::FORM_CODE_INCORRECT);

            return StrategyResult::fail($attempt, $code, 'Incorrect code.');
        }

        // Map verifiable -> User via EmailAddress.
        $email = EmailAddress::query()->withoutGlobalScopes()->where('id', $verification->verifiable_id)->first();
        $user = $email ? User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first() : null;

        if ($user === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'User not found.', 422);
        }

        if ($user->banned) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_BANNED, 'This user is banned.', 403);
        }
        if ($user->locked && $user->lockout_expires_at?->isFuture()) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_LOCKED, 'This user is temporarily locked.', 403);
        }

        return StrategyResult::ok($attempt, $user);
    }

    private function resolveEmailAddress(SignInAttempt $attempt, array $params): ?EmailAddress
    {
        $emailAddressId = $params['email_address_id'] ?? null;
        if (is_string($emailAddressId) && $emailAddressId !== '') {
            return EmailAddress::query()
                ->withoutGlobalScopes()
                ->where('id', $emailAddressId)
                ->where('environment_id', $attempt->environment_id)
                ->first();
        }

        return EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('email_address', strtolower((string) $attempt->identifier))
            ->first();
    }
}
