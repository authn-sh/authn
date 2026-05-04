<?php

declare(strict_types=1);

namespace App\Auth\Captcha;

use App\Auth\TestMode\Detector;
use App\Models\Environment;

/**
 * Captcha enforcement policy (PLAN §13.4).
 *
 * v0.1 ships the policy hook the sign-in / sign-up controllers consult;
 * the actual provider call (hCaptcha / Turnstile verification) is wired
 * in alongside the dashboard captcha-config UI. The hook gives us:
 *
 *   - test-identifier bypass (CI doesn't have a captcha widget),
 *   - skip when no provider configured on the env,
 *   - structured ok/missing/invalid result the caller can map to a 422.
 */
final class CaptchaPolicy
{
    public const REASON_OK = 'ok';

    public const REASON_DISABLED = 'disabled';

    public const REASON_TEST_BYPASS = 'test_bypass';

    public const REASON_MISSING_TOKEN = 'missing_token';

    /**
     * Returns one of the REASON_* constants. Callers treat OK / DISABLED /
     * TEST_BYPASS as success and MISSING_TOKEN as a captcha_invalid error.
     */
    public static function evaluate(Environment $environment, ?string $captchaToken, ?string $identifier): string
    {
        if ($identifier !== null && Detector::isTestIdentifier($identifier)) {
            return self::REASON_TEST_BYPASS;
        }
        $appearance = is_array($environment->appearance) ? $environment->appearance : [];
        $captcha = is_array($appearance['captcha'] ?? null) ? $appearance['captcha'] : [];
        $provider = (string) ($captcha['provider'] ?? 'none');
        if ($provider === '' || $provider === 'none') {
            return self::REASON_DISABLED;
        }
        if (! is_string($captchaToken) || $captchaToken === '') {
            return self::REASON_MISSING_TOKEN;
        }

        // v0.1: trust the supplied token. The provider verifier
        // (hCaptcha / Turnstile HTTP call) lands alongside the dashboard
        // captcha-config UI in a follow-up — wiring is in place.
        return self::REASON_OK;
    }
}
