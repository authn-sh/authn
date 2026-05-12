<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Support\VirtualAuthenticator;
use Tests\DuskTestCase;

/**
 * v0.5 carryover (AU-14): end-to-end passkey enrollment ceremony driven
 * against Chrome's virtual authenticator via CDP. Replaces the fixture-only
 * coverage that v0.5 shipped (those tests stay in tests/Feature/Passkey/*
 * for fast unit-level coverage of the platform-agnostic helpers).
 *
 * The virtual authenticator is added on test setUp via the standard
 * WebAuthn CDP domain (`WebAuthn.enable` + `WebAuthn.addVirtualAuthenticator`)
 * and torn down at the end so each test sees a fresh, empty authenticator.
 */
final class PasskeyEnrollmentTest extends DuskTestCase
{
    public function test_operator_enrolls_a_passkey_via_virtual_authenticator(): void
    {
        $email = (string) (env('DUSK_OPERATOR_EMAIL') ?: 'op@example.com');
        $password = (string) (env('DUSK_OPERATOR_PASSWORD') ?: 'super-secret-password');

        $this->browse(function (Browser $browser) use ($email, $password): void {
            VirtualAuthenticator::install($browser);

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

            $credentials = VirtualAuthenticator::credentials($browser);
            expect(count($credentials))->toBeGreaterThanOrEqual(1);

            VirtualAuthenticator::remove($browser);
        });
    }
}
