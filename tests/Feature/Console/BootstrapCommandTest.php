<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// `.env` on developer machines often pre-sets these to a real operator,
// which would shadow the `--email` / `--password` options and make the
// "missing arg" test below pass spuriously. Clear them per-test so the
// command sees only what the test explicitly passes.
beforeEach(function (): void {
    foreach (['AUTHN_BOOTSTRAP_ADMIN_EMAIL', 'AUTHN_BOOTSTRAP_ADMIN_PASSWORD'] as $key) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
});

it('runs successfully when env vars are set', function (): void {
    config(['app.url' => 'https://authn.local']);

    $this->artisan('authn:bootstrap', [
        '--email' => 'op@example.com',
        '--password' => 'super-secret',
        '--workspace' => 'Acme Inc',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('authn.sh bootstrap complete.')
        ->expectsOutputToContain('sk_live_')
        ->expectsOutputToContain('pk_live_');

    expect(Project::where('is_system', true)->count())->toBe(1);
    expect(ApiKey::count())->toBe(2);
});

it('is a no-op on the second run', function (): void {
    config(['app.url' => 'https://authn.local']);

    $this->artisan('authn:bootstrap', [
        '--email' => 'op@example.com',
        '--password' => 'super-secret',
    ])->assertSuccessful();

    $this->artisan('authn:bootstrap', [
        '--email' => 'op@example.com',
        '--password' => 'super-secret',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('_admin already exists');

    expect(Project::where('is_system', true)->count())->toBe(1);
    expect(ApiKey::count())->toBe(2);
});

it('fails with a clear error when email is missing', function (): void {
    $this->artisan('authn:bootstrap', ['--password' => 'super-secret'])
        ->assertFailed()
        ->expectsOutputToContain('Bootstrap requires admin_email and admin_password');
});
