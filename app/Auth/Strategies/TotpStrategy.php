<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\ErrorCodes;
use App\Auth\Mfa\TotpEnrolmentService;
use App\Models\EmailAddress;
use App\Models\SignInAttempt;
use App\Models\TotpSecret;
use App\Models\User;
use App\Models\Verification;

/**
 * Second-factor TOTP strategy. Issues a Challenge with no out-of-band
 * material — the user already holds the secret on their authenticator
 * — and the answer is the 6-digit code typed in. Reuses the AU-3
 * verification primitive (TotpEnrolmentService::verify), which honours
 * the ±1 step window and `last_used_step` replay protection.
 */
final class TotpStrategy implements Strategy
{
    public function __construct(private readonly TotpEnrolmentService $enrolment) {}

    public function name(): string
    {
        return Verification::STRATEGY_TOTP;
    }

    public function requiresPrepare(): bool
    {
        return false;
    }

    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        return StrategyResult::fail($attempt, ErrorCodes::PREPARE_NOT_REQUIRED, 'TOTP strategy does not need a prepare step.', 422);
    }

    public function attempt(SignInAttempt $attempt, array $params): StrategyResult
    {
        $code = $params['code'] ?? null;
        if (! is_string($code) || $code === '') {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_PARAM_NIL, 'code is required.');
        }

        $user = $this->resolveUser($attempt);
        if ($user === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'User not found for this attempt.', 422);
        }

        $secret = TotpSecret::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->first();
        if ($secret === null) {
            return StrategyResult::fail($attempt, ErrorCodes::TOTP_NOT_FOUND, 'No verified TOTP secret on this user.', 422);
        }

        if (! $this->enrolment->verify($secret, $code)) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_CODE_INCORRECT, 'TOTP code is incorrect or expired.');
        }

        $verification = $params['verification'] ?? null;
        if ($verification instanceof Verification) {
            $verification->forceFill([
                'status' => Verification::STATUS_VERIFIED,
                'verified_at' => now(),
            ])->save();
        }

        return StrategyResult::ok($attempt, $user, $verification instanceof Verification ? $verification->fresh() ?? $verification : null);
    }

    private function resolveUser(SignInAttempt $attempt): ?User
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
