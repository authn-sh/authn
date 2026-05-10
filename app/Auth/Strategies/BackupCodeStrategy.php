<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\ErrorCodes;
use App\Auth\Mfa\BackupCodesService;
use App\Auth\Mfa\ConsumeResult;
use App\Models\EmailAddress;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;

/**
 * Second-factor backup-code strategy. Single-use Crockford-base32
 * `xxxx-xxxx` codes minted by AU-4. The answer is the plaintext code
 * the user copied / printed at generation time; on first match the
 * matching row's `consumed_at` is stamped via BackupCodesService.
 */
final class BackupCodeStrategy implements Strategy
{
    public function __construct(private readonly BackupCodesService $service) {}

    public function name(): string
    {
        return Verification::STRATEGY_BACKUP_CODE;
    }

    public function requiresPrepare(): bool
    {
        return false;
    }

    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        return StrategyResult::fail($attempt, ErrorCodes::PREPARE_NOT_REQUIRED, 'Backup-code strategy does not need a prepare step.', 422);
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

        $outcome = $this->service->tryConsume($user, $code);
        if ($outcome === ConsumeResult::AlreadyUsed) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_CODE_ALREADY_USED, 'Backup code has already been used.');
        }
        if ($outcome !== ConsumeResult::Ok) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_CODE_INCORRECT, 'Backup code is incorrect.');
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
