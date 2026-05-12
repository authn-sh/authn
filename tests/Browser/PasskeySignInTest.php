<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Support\VirtualAuthenticator;
use Tests\DuskTestCase;

/**
 * v0.5 carryover (AU-14): companion to PasskeyEnrollmentTest covering the
 * `navigator.credentials.get()` half. We register a virtual authenticator
 * and confirm Chrome reports WebAuthn as supported in the page context —
 * the same precondition the SDK's sign-in factor selector tests for
 * before rendering the passkey button.
 *
 * The full sign-out → sign-in-with-passkey roundtrip lands in a follow-up
 * once the credential-seeding fixture is wired into the CI bootstrap.
 */
final class PasskeySignInTest extends DuskTestCase
{
    public function test_virtual_authenticator_signals_passkey_support_on_sign_in(): void
    {
        $this->browse(function (Browser $browser): void {
            VirtualAuthenticator::install($browser);

            $browser->visit('/sign-in')
                ->waitFor('[data-testid="authn-signin"]', 10)
                ->waitFor('#authn-signin-identifier', 5)
                ->pause(500);

            $supported = $browser->driver->executeScript(
                "return typeof window.PublicKeyCredential === 'function';",
            );
            expect($supported)->toBeTrue();

            $platformAuthenticatorAvailable = $browser->driver->executeScript(
                "return new Promise(resolve => {
                    if (typeof window.PublicKeyCredential?.isUserVerifyingPlatformAuthenticatorAvailable !== 'function') {
                        resolve(false); return;
                    }
                    window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
                        .then(v => resolve(Boolean(v)), () => resolve(false));
                });",
            );
            expect($platformAuthenticatorAvailable)->toBeTrue();

            VirtualAuthenticator::remove($browser);
        });
    }
}
