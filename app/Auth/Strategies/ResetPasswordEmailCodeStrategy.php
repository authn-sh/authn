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
 * Forgot-password flow: same email-OTP plumbing as EmailCodeStrategy
 * but routed through the `reset_password_code` template (AU-14 wires
 * the template). On successful attempt the SignInController flips
 * `attempt->status` to `needs_new_password`; the SDK then calls
 * `POST /v1/client/sign_ins/{id}/reset_password`.
 */
final class ResetPasswordEmailCodeStrategy implements Strategy
{
    public const PURPOSE = VerificationCode::PURPOSE_RESET_PASSWORD_EMAIL_CODE;

    public const TTL_SECONDS = 600;

    public function __construct(private readonly VerificationManager $verifications) {}

    public function name(): string
    {
        return Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE;
    }

    public function requiresPrepare(): bool
    {
        return true;
    }

    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        $emailAddress = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('email_address', strtolower((string) $attempt->identifier))
            ->first();

        if ($emailAddress === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'No email address matches this identifier.', 422);
        }

        $verification = $this->verifications->start(
            $emailAddress,
            Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE,
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
            $errorCode = $verification->fresh()->status === Verification::STATUS_FAILED
                ? ErrorCodes::VERIFICATION_FAILED
                : ($verification->fresh()->status === Verification::STATUS_EXPIRED
                    ? ErrorCodes::VERIFICATION_EXPIRED
                    : ErrorCodes::FORM_CODE_INCORRECT);

            return StrategyResult::fail($attempt, $errorCode, 'Incorrect code.');
        }

        $email = EmailAddress::query()->withoutGlobalScopes()->where('id', $verification->verifiable_id)->first();
        $user = $email ? User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first() : null;

        if ($user === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'User not found.', 422);
        }

        return StrategyResult::ok($attempt, $user);
    }
}
