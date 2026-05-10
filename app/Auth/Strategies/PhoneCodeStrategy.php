<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\ErrorCodes;
use App\Jobs\Sms\SendVerificationSms;
use App\Models\EmailAddress;
use App\Models\PhoneNumber;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Services\Verification\VerificationManager;

/**
 * SMS-OTP strategy. Two flows share this implementation:
 *
 *   - First-factor sign-in via phone identifier (resolved through the
 *     attempt's identifier field; symmetric with EmailCodeStrategy).
 *   - Second-factor MFA via the user's `reserved_for_second_factor`
 *     PhoneNumber row (resolved off the user inferred from the attempt's
 *     email identifier — mirror of TotpStrategy).
 *
 * `prepare` mints a 6-digit code, persists its sha256 on a VerificationCode
 * row, and dispatches `SendVerificationSms`. `attempt` consumes the code via
 * `VerificationManager::attempt`, which handles the Argon2id-style replay
 * protection (single-use, attempts capped at 5).
 */
final class PhoneCodeStrategy implements Strategy
{
    public const PURPOSE = 'phone_code';

    public const TTL_SECONDS = 600;

    public function __construct(private readonly VerificationManager $verifications) {}

    public function name(): string
    {
        return Verification::STRATEGY_PHONE_CODE;
    }

    public function requiresPrepare(): bool
    {
        return true;
    }

    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        $phone = $this->resolvePhone($attempt, $params);
        if ($phone instanceof StrategyResult) {
            return $phone;
        }

        $verification = $this->verifications->start(
            $phone,
            Verification::STRATEGY_PHONE_CODE,
            self::TTL_SECONDS,
        );
        $code = $this->verifications->mintNumericCode($verification, self::PURPOSE, self::TTL_SECONDS);

        SendVerificationSms::dispatch(
            $attempt->environment_id,
            $phone->phone_number,
            $code,
            $phone->id,
            $verification->id,
        );

        return StrategyResult::ok($attempt, verification: $verification);
    }

    public function attempt(SignInAttempt $attempt, array $params): StrategyResult
    {
        $code = $params['code'] ?? null;
        if (! is_string($code) || $code === '') {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_PARAM_NIL, 'code is required.');
        }
        $verification = $params['verification'] ?? null;
        if (! $verification instanceof Verification) {
            return StrategyResult::fail($attempt, ErrorCodes::VERIFICATION_FAILED, 'No verification is in progress for this attempt.', 422);
        }

        $ok = $this->verifications->attempt($verification, $code);
        if (! $ok) {
            $errCode = match ($verification->fresh()->status) {
                Verification::STATUS_FAILED => ErrorCodes::VERIFICATION_FAILED,
                Verification::STATUS_EXPIRED => ErrorCodes::VERIFICATION_EXPIRED,
                default => ErrorCodes::FORM_CODE_INCORRECT,
            };

            return StrategyResult::fail($attempt, $errCode, 'Incorrect code.', verification: $verification->fresh() ?? $verification);
        }

        // The verifiable is the PhoneNumber row. Resolve the owning user.
        $phone = PhoneNumber::query()->withoutGlobalScopes()->where('id', $verification->verifiable_id)->first();
        $user = $phone !== null
            ? User::query()->withoutGlobalScopes()->where('id', $phone->user_id)->first()
            : null;

        if ($user === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'User not found.', 422);
        }
        if ($user->banned) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_BANNED, 'This user is banned.', 403);
        }
        if ($user->locked && $user->lockout_expires_at?->isFuture()) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_LOCKED, 'This user is temporarily locked.', 403);
        }

        // First-factor sign-up via phone-attribute path may flip
        // verified_at when the phone wasn't yet verified; second-factor
        // MFA never re-verifies (the row was already verified at enroll).
        if ($phone->verified_at === null) {
            $phone->markVerified();
        }

        return StrategyResult::ok($attempt, $user, $verification->fresh() ?? $verification);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function resolvePhone(SignInAttempt $attempt, array $params): PhoneNumber|StrategyResult
    {
        $phoneId = $params['phone_number_id'] ?? null;
        if (is_string($phoneId) && $phoneId !== '') {
            $row = PhoneNumber::query()
                ->withoutGlobalScopes()
                ->where('id', $phoneId)
                ->where('environment_id', $attempt->environment_id)
                ->first();
            if ($row === null) {
                return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'No phone number matches that id.', 422);
            }

            return $row;
        }

        $user = $this->resolveUserFromAttempt($attempt);
        if ($user === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_PARAM_NIL, 'Cannot resolve a phone for this attempt; pass phone_number_id.', 422);
        }

        // Second-factor: prefer the row marked default_second_factor, then
        // any reserved-for-second-factor row, then primary.
        $row = PhoneNumber::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->where('reserved_for_second_factor', true)
            ->orderByDesc('default_second_factor')
            ->orderByDesc('is_primary')
            ->first();
        if ($row === null) {
            return StrategyResult::fail($attempt, 'phone_not_found', 'User has no phone reserved for second factor.', 422);
        }

        return $row;
    }

    private function resolveUserFromAttempt(SignInAttempt $attempt): ?User
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
}
