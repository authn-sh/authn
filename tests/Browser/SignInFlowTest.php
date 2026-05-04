<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Smoke test: sign in as the bootstrapped operator, verify the dashboard
 * loads, click the user avatar, sign out, end up back on /sign-in.
 *
 * Catches the "Inertia tried to resolve AccountPortal/SignIn inside the
 * Dashboard app" regression and the "no __session cookie set on sign-in"
 * regression — both of which broke this exact flow during AU-15.
 */
final class SignInFlowTest extends DuskTestCase
{
    public function test_operator_can_sign_in_and_sign_out(): void
    {
        $email = (string) (env('DUSK_OPERATOR_EMAIL') ?: 'op@example.com');
        $password = (string) (env('DUSK_OPERATOR_PASSWORD') ?: 'super-secret-password');

        $this->browse(function (Browser $browser) use ($email, $password): void {
            $browser->visit('/sign-in')
                ->waitFor('[data-testid="authn-signin"]', 10)
                ->waitFor('#authn-signin-identifier', 5)
                ->pause(500)
                ->typeSlowly('#authn-signin-identifier', $email, 20)
                ->pause(200)
                ->assertInputValue('#authn-signin-identifier', $email)
                ->click('[data-testid="authn-signin"] button[type=submit]')
                ->waitFor('input[type=password]', 10)
                ->typeSlowly('input[type=password]', $password, 20)
                ->click('[data-testid="authn-signin-factor-one"] button[type=submit]')
                ->waitUntil("window.location.pathname.startsWith('/dashboard')", 10)
                ->waitFor('[data-testid="authn-userbutton-trigger"]', 10)
                ->click('[data-testid="authn-userbutton-trigger"]')
                ->waitFor('[data-testid="authn-userbutton-signout"]', 5)
                ->click('[data-testid="authn-userbutton-signout"]')
                ->waitUntil("window.location.pathname.startsWith('/sign-in')", 10)
                ->assertPathBeginsWith('/sign-in');
        });
    }
}
