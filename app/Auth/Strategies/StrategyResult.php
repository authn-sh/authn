<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Models\SignInAttempt;
use App\Models\User;

/**
 * Common result shape returned by every Strategy method. The controller
 * doesn't need to know about strategy internals — it inspects `success`,
 * `errorCode`, `errorMessage`, then renders the SignInAttempt with any
 * resulting Verification or User attached.
 */
final class StrategyResult
{
    private function __construct(
        public readonly bool $success,
        public readonly SignInAttempt $attempt,
        public readonly ?User $user = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly int $httpStatus = 200,
    ) {}

    public static function ok(SignInAttempt $attempt, ?User $user = null): self
    {
        return new self(true, $attempt, $user);
    }

    public static function fail(SignInAttempt $attempt, string $code, string $message, int $httpStatus = 422): self
    {
        return new self(false, $attempt, null, $code, $message, $httpStatus);
    }
}
