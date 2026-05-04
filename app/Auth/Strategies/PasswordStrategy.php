<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\BruteForce\RecordFailedAttempt;
use App\Auth\ErrorCodes;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;

final class PasswordStrategy implements Strategy
{
    public function __construct(private readonly RecordFailedAttempt $bruteForce) {}

    public function name(): string
    {
        return Verification::STRATEGY_PASSWORD;
    }

    public function requiresPrepare(): bool
    {
        return false;
    }

    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        return StrategyResult::fail($attempt, ErrorCodes::PREPARE_NOT_REQUIRED, 'Password strategy does not need a prepare step.', 422);
    }

    public function attempt(SignInAttempt $attempt, array $params): StrategyResult
    {
        $password = $params['password'] ?? null;
        if (! is_string($password) || $password === '') {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_PARAM_NIL, 'password is required.');
        }

        $identifier = (string) $attempt->identifier;
        $user = $this->resolveUserByEmail($identifier, $attempt->environment_id);

        // Same error code regardless of whether the user exists or the
        // password is wrong, so we don't leak user existence. (The
        // timing-attack mitigation that runs Hash::check on a decoy when
        // the user doesn't exist will land alongside HIBP + brute-force
        // lockout in AU-18.)
        if ($user === null || ! $user->checkPassword($password)) {
            $env = Environment::query()->withoutGlobalScopes()->where('id', $attempt->environment_id)->first();
            if ($env !== null) {
                $this->bruteForce->record($env, $identifier, $user);
            }

            return StrategyResult::fail($attempt, ErrorCodes::FORM_PASSWORD_INCORRECT, 'Password is incorrect. Try again, or use another method.', 422);
        }

        if ($user->banned) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_BANNED, 'This user is banned.', 403);
        }
        if ($user->locked && $user->lockout_expires_at?->isFuture()) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_LOCKED, 'This user is temporarily locked.', 403);
        }

        // Reset the failure counter on a successful sign-in.
        $env = Environment::query()->withoutGlobalScopes()->where('id', $attempt->environment_id)->first();
        if ($env !== null) {
            $this->bruteForce->reset($env, $identifier);
        }

        return StrategyResult::ok($attempt, $user);
    }

    private function resolveUserByEmail(string $identifier, string $environmentId): ?User
    {
        $row = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $environmentId)
            ->where('email_address', strtolower($identifier))
            ->first();

        if ($row === null) {
            return null;
        }

        return User::query()
            ->withoutGlobalScopes()
            ->where('id', $row->user_id)
            ->first();
    }
}
