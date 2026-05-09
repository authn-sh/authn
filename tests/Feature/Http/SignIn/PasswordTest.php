<?php

declare(strict_types=1);

use App\Models\Session;
use Tests\Feature\Http\SignIn\SignInTestSupport;

it('completes a sign-in when identifier + strategy=password + password are supplied in one call', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);

    $response = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
            'strategy' => 'password',
            'password' => 'super-secret-password',
        ]);

    $response->assertOk()
        ->assertJsonPath('response.status', 'complete')
        ->assertJsonPath('response.identifier', 'alice@example.com');

    $sessionId = $response->json('response.created_session_id');
    expect($sessionId)->toStartWith('sess_');
    expect(Session::query()->withoutGlobalScopes()->where('id', $sessionId)->where('status', 'active')->exists())->toBeTrue();
});

it('returns form_password_incorrect on a wrong password', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);

    $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
            'strategy' => 'password',
            'password' => 'wrong-password',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'form_password_incorrect');
});

it('returns form_password_incorrect for an unknown identifier (no user-existence leak)', function (): void {
    $f = SignInTestSupport::bootEnv();

    $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'ghost@example.com',
            'strategy' => 'password',
            'password' => 'whatever',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'form_password_incorrect');
});

it('refuses sign-in for a banned user', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    $bundle['user']->forceFill(['banned' => true])->saveQuietly();

    $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
            'strategy' => 'password',
            'password' => 'super-secret-password',
        ])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'user_banned');
});

it('refuses sign-in for a locked user with a future lockout_expires_at', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    $bundle['user']->forceFill([
        'locked' => true,
        'lockout_expires_at' => now()->addHour(),
    ])->saveQuietly();

    $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
            'strategy' => 'password',
            'password' => 'super-secret-password',
        ])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'user_locked');
});

it('returns supported_first_factors on identifier-only create', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);

    $response = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);

    $response->assertOk()
        ->assertJsonPath('response.status', 'needs_first_factor');

    $strategies = collect($response->json('response.supported_first_factors'))->pluck('strategy');
    expect($strategies->contains('password'))->toBeTrue();
    expect($strategies->contains('email_code'))->toBeTrue();
    expect($strategies->contains('reset_password_email_code'))->toBeTrue();
});
