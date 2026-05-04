<?php

declare(strict_types=1);

namespace App\Auth\TestMode;

/**
 * Identifier-pattern detection for test mode (PLAN §9.11).
 *
 * Pure static helpers — no DB lookups, O(1) per call. The patterns are
 * fixed by the spec:
 *
 *   email — local-part contains the substring `+authn_test` (case-insensitive),
 *           e.g. `alice+authn_test@example.com`.
 *   phone — NANP reserved range `+1 (555) 555-0100` … `+1 (555) 555-0199`,
 *           E.164 form `+15555550100` … `+15555550199`.
 */
final class Detector
{
    public const FIXED_OTP = '424242';

    public const TEST_EMAIL_TAG = '+authn_test';

    private const TEST_PHONE_REGEX = '/^\+15555550(?:1[0-9]{2})$/';

    public static function isTestEmail(string $email): bool
    {
        $atPos = strrpos($email, '@');
        if ($atPos === false) {
            return false;
        }
        $local = substr($email, 0, $atPos);

        return stripos($local, self::TEST_EMAIL_TAG) !== false;
    }

    public static function isTestPhone(string $e164): bool
    {
        return preg_match(self::TEST_PHONE_REGEX, $e164) === 1;
    }

    public static function isTestIdentifier(string $identifier): bool
    {
        return self::isTestEmail($identifier) || self::isTestPhone($identifier);
    }
}
