<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Smoke test for v0.3 MFA: signs in as the bootstrapped operator,
 * navigates to the Account Portal `/user` page, asserts the JS-3
 * Security section renders, and clicks the "Add authenticator app"
 * trigger to verify the TOTP enrol dialog opens against the live
 * FAPI backend.
 *
 * The full second-factor sign-in flow (TOTP code entry on sign-in)
 * needs a pre-enrolled TotpSecret in the live database, which the
 * Dusk runner doesn't currently seed; that path is covered end-to-end
 * by the Pest suite in tests/Feature/Fapi/MfaSecondFactorFlowTest.
 */
final class MfaSecurityFlowTest extends DuskTestCase
{
    public function test_user_profile_security_section_renders_and_totp_enroll_opens(): void
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
                ->waitFor('[data-testid="authn-userprofile-security"]', 10)
                ->assertVisible('[data-testid="authn-userprofile-security-enroll-totp"]')
                ->click('[data-testid="authn-userprofile-security-enroll-totp"]')
                ->waitFor('[data-testid="authn-totp-enroll"]', 10)
                ->assertVisible('[data-testid="authn-totp-enroll"]');
        });
    }
}
