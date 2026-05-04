<?php

declare(strict_types=1);

namespace App\Auth\TestMode;

use App\Models\Environment;

/**
 * Resolves the per-(env, identifier) test-mode disposition.
 *
 * Three outcomes:
 *
 *   STATUS_NORMAL    — identifier is not reserved, OR test_mode = disabled.
 *                      Treat the request like any other.
 *   STATUS_TEST      — identifier is reserved AND test_mode = enabled.
 *                      The caller short-circuits captcha, brute force,
 *                      driver dispatch; verifications use the fixed OTP;
 *                      attempts/sessions/events get was_test=true.
 *   STATUS_REJECTED  — identifier is reserved AND test_mode = rejected.
 *                      Caller returns 422 test_identifier_forbidden
 *                      without creating any state.
 */
final class Policy
{
    public const STATUS_NORMAL = 'normal';

    public const STATUS_TEST = 'test';

    public const STATUS_REJECTED = 'rejected';

    public const ERROR_CODE = 'test_identifier_forbidden';

    public static function resolve(Environment $environment, ?string $identifier): string
    {
        if (! is_string($identifier) || $identifier === '') {
            return self::STATUS_NORMAL;
        }
        if (! Detector::isTestIdentifier($identifier)) {
            return self::STATUS_NORMAL;
        }

        return match ($environment->testMode()) {
            Environment::TEST_MODE_ENABLED => self::STATUS_TEST,
            Environment::TEST_MODE_REJECTED => self::STATUS_REJECTED,
            default => self::STATUS_NORMAL,
        };
    }

    public static function isTestAttempt(Environment $environment, ?string $identifier): bool
    {
        return self::resolve($environment, $identifier) === self::STATUS_TEST;
    }
}
