<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Support\VirtualAuthenticator;
use Tests\DuskTestCase;

/**
 * v0.5 carryover (AU-14): full passkey sign-in ceremony driven against
 * the same virtual authenticator that registered the credential. We
 * sign in once with password, enrol the passkey, sign out, and re-sign
 * in via the passkey factor selector — exercising the
 * `/v1/me/passkeys/begin-registration` → `navigator.credentials.create()`
 * and `/v1/sign-in/begin-passkey` → `navigator.credentials.get()` paths
 * end-to-end against the real Account Portal bundle.
 */
final class PasskeySignInTest extends DuskTestCase
{
    public function test_operator_signs_in_with_a_previously_enrolled_passkey(): void
    {
        $email = (string) (env('DUSK_OPERATOR_EMAIL') ?: 'op@example.com');
        $password = (string) (env('DUSK_OPERATOR_PASSWORD') ?: 'super-secret-password');

        $this->browse(function (Browser $browser) use ($email, $password): void {
            VirtualAuthenticator::install($browser);

            // 1) Sign in with the password, enrol the passkey.
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
                ->visit('/user/passkeys')
                ->waitFor('[data-testid="authn-passkeys-panel"]', 10)
                ->click('[data-testid="authn-passkey-add-cta"]')
                ->waitFor('[data-testid="authn-passkey-nickname-input"]', 10)
                ->typeSlowly('[data-testid="authn-passkey-nickname-input"]', 'Dusk Authenticator', 20)
                ->click('[data-testid="authn-passkey-add-submit"]')
                ->waitFor('[data-testid^="authn-passkey-row-"]', 15);

            // 2) Sign out, then sign back in using the passkey factor.
            $browser->click('[data-testid="authn-userbutton-trigger"]')
                ->waitFor('[data-testid="authn-userbutton-signout"]', 5)
                ->click('[data-testid="authn-userbutton-signout"]')
                ->waitUntil("window.location.pathname.startsWith('/sign-in')", 10)
                ->waitFor('[data-testid="authn-signin"]', 10)
                ->typeSlowly('#authn-signin-identifier', $email, 20)
                ->click('[data-testid="authn-signin"] button[type=submit]')
                ->waitFor('[data-testid="authn-passkey-button"]', 10)
                ->click('[data-testid="authn-passkey-button"]')
                ->waitUntil("window.location.pathname.startsWith('/dashboard')", 15);

            VirtualAuthenticator::remove($browser);
        });
    }
}
