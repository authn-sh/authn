<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;

/**
 * Common result shape returned by every Strategy method. The controller
 * doesn't need to know about strategy internals — it inspects `success`,
 * `errorCode`, `errorMessage`, then wraps the resulting Verification in a
 * Challenge envelope.
 */
final class StrategyResult
{
    private function __construct(
        public readonly bool $success,
        public readonly SignInAttempt $attempt,
        public readonly ?User $user = null,
        public readonly ?Verification $verification = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly int $httpStatus = 200,
    ) {}

    public static function ok(SignInAttempt $attempt, ?User $user = null, ?Verification $verification = null): self
    {
        return new self(true, $attempt, $user, $verification);
    }

    public static function fail(SignInAttempt $attempt, string $code, string $message, int $httpStatus = 422, ?Verification $verification = null): self
    {
        return new self(false, $attempt, null, $verification, $code, $message, $httpStatus);
    }
}
