<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

final class CreateProjectFlowTest extends DuskTestCase
{
    public function test_operator_creates_a_project_and_lands_on_its_overview(): void
    {
        $slug = 'dusk-'.bin2hex(random_bytes(3));
        $email = (string) (env('DUSK_OPERATOR_EMAIL') ?: 'op@example.com');
        $password = (string) (env('DUSK_OPERATOR_PASSWORD') ?: 'super-secret-password');

        $this->browse(function (Browser $browser) use ($slug, $email, $password): void {
            $browser->visit('/sign-in')
                ->waitFor('[data-testid="authn-signin"]', 10)
                ->waitFor('#authn-signin-identifier', 5)
                ->typeSlowly('#authn-signin-identifier', $email, 20)
                ->click('[data-testid="authn-signin"] button[type=submit]')
                ->waitFor('input[type=password]', 10)
                ->typeSlowly('input[type=password]', $password, 20)
                ->click('[data-testid="authn-signin-factor-one"] button[type=submit]')
                ->waitUntil("window.location.pathname.startsWith('/dashboard')", 10)
                ->visit('/dashboard/create-project')
                ->waitFor('input[name="name"]', 5)
                ->type('name', 'Dusk Inc')
                ->type('slug', $slug)
                ->press('Create project')
                ->waitUntil("window.location.pathname === '/dashboard/{$slug}/production/overview'", 10)
                ->assertAttribute("a[href='/dashboard/{$slug}/production/users']", 'href', "/dashboard/{$slug}/production/users");
        });
    }
}
