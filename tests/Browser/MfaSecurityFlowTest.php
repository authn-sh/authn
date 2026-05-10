<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Smoke test for v0.3 MFA: signs in as the bootstrapped operator,
 * navigates to the Account Portal `/user` page, asserts the JS-3
 * Security tab renders the section panel with the "Add authenticator
 * app" trigger button.
 *
 * Verifies that bumping `@authn-sh/sdk-react` to 0.3.0 wired the
 * `<UserProfileSecuritySection />` into the Account Portal's mounted
 * `<UserProfile />`. The full enrol-and-verify flow (clicking enrol
 * → QR render → typing the first OTP) plus the second-factor
 * sign-in flow are out of scope for this smoke check — the latter
 * needs a pre-enrolled TotpSecret in the live database that the
 * Dusk runner doesn't currently seed; both paths are covered
 * end-to-end by the Pest suite in tests/Feature/Fapi/MfaSecondFactorFlowTest
 * (AU-12) and tests/Feature/Mfa/MeTotpEnrollmentTest (AU-3).
 */
final class MfaSecurityFlowTest extends DuskTestCase
{
    public function test_user_profile_security_tab_renders(): void
    {
        $email = (string) (env('DUSK_OPERATOR_EMAIL') ?: 'op@example.com');
        $password = (string) (env('DUSK_OPERATOR_PASSWORD') ?: 'super-secret-password');

        $this->browse(function (Browser $browser) use ($email, $password): void {
            $browser->visit('/sign-in')
                ->waitFor('[data-testid="authn-signin"]', 10)
                ->waitFor('#authn-signin-identifier', 5)
                ->pause(500)
                ->typeSlowly('#authn-signin-identifier', $email, 20)
                ->click('[data-testid="authn-signin"] button[type=submit]')
                ->waitFor('input[type=password]', 10)
                ->typeSlowly('input[type=password]', $password, 20)
                ->click('[data-testid="authn-signin-factor-one"] button[type=submit]')
                ->waitUntil("window.location.pathname.startsWith('/dashboard')", 10)
                ->visit('/user')
                ->waitFor('[data-testid="authn-userprofile"]', 10)
                ->waitForTextIn('[role="tablist"]', 'Security', 10)
                ->click('[role="tab"]:nth-of-type(4)')
                ->waitFor('[data-testid="authn-userprofile-security"]', 10)
                ->assertVisible('[data-testid="authn-userprofile-security-enroll-totp"]');
        });
    }
}
