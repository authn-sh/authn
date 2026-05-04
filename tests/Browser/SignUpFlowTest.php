<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\Mailpit;
use Tests\DuskTestCase;

/**
 * Smoke test: sign up a fresh user against the `_admin` env's Account
 * Portal, click through the email-code verification step (code read from
 * Mailpit), and end up on /dashboard/create-workspace because the new
 * user has no organization yet.
 *
 * Each run uses a unique `dusk-{nonce}@example.com` so the test is
 * idempotent against a long-lived Dusk database.
 */
final class SignUpFlowTest extends DuskTestCase
{
    public function test_new_visitor_signs_up_and_lands_on_create_workspace(): void
    {
        $email = 'dusk-'.bin2hex(random_bytes(4)).'@example.com';
        $mailpit = Mailpit::default();
        $mailpit->clear();

        $this->browse(function (Browser $browser) use ($email, $mailpit): void {
            $browser->visit('/sign-up')
                ->waitFor('[data-testid="authn-signup"]', 10)
                ->waitFor('#authn-signup-firstname', 5)
                ->typeSlowly('#authn-signup-firstname', 'Dusk', 20)
                ->typeSlowly('#authn-signup-lastname', 'Tester', 20)
                ->typeSlowly('#authn-signup-email', $email, 20)
                ->typeSlowly('#authn-signup-password', 'super-secret-password', 20)
                ->click('[data-testid="authn-signup"] button[type=submit]')
                ->waitFor('[data-testid="authn-signup-verify-email"]', 10);

            $code = $mailpit->fetchVerificationCode($email, 10);

            $browser->typeSlowly('[data-testid="authn-signup-verify-email"] input', $code, 20)
                ->click('[data-testid="authn-signup-verify-email"] button[type=submit]')
                ->waitUntil("window.location.pathname === '/dashboard/create-workspace'", 10)
                ->assertPathIs('/dashboard/create-workspace');
        });

        // Force-delete so the table doesn't grow across runs.
        User::query()
            ->whereHas('emailAddresses', fn ($q) => $q->where('email_address', $email))
            ->each(fn (User $u) => $u->forceDelete());
    }
}
