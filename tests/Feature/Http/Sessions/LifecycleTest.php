<?php

declare(strict_types=1);

use App\Models\Session;
use App\Models\SessionActivity;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

it('touch updates last_active_at and writes one SessionActivity per debounce window', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $sid = $bs['session']->id;
    $cookie = $bs['cookie'];

    $before = $bs['session']->last_active_at;

    $first = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin'], 'User-Agent' => 'Mozilla/5.0 Chrome/100'])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$sid}/touch", []);
    $first->assertOk()->assertJsonPath('response.id', $sid);

    expect(SessionActivity::query()->where('session_id', $sid)->count())->toBe(1);

    // Second touch within debounce window — no new activity row written.
    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$sid}/touch", [])
        ->assertOk();
    expect(SessionActivity::query()->where('session_id', $sid)->count())->toBe(1);

    expect(Session::query()->withoutGlobalScopes()->where('id', $sid)->first()->last_active_at?->getTimestamp())
        ->toBeGreaterThanOrEqual($before?->getTimestamp() ?? 0);
});

it('end flips status to ended; subsequent token mint returns 401', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $sid = $bs['session']->id;
    $cookie = $bs['cookie'];

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$sid}/end", [])
        ->assertOk()
        ->assertJsonPath('response.status', 'ended');

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$sid}/tokens", [])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'session_revoked');
});

it('remove flips status to removed; subsequent token mint returns 401', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $sid = $bs['session']->id;
    $cookie = $bs['cookie'];

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$sid}/remove", [])
        ->assertOk()
        ->assertJsonPath('response.status', 'removed');

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$sid}/tokens", [])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'session_revoked');
});

it('rate-limits token mints to 3 per 30s with Retry-After', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $sid = $bs['session']->id;
    $cookie = $bs['cookie'];

    for ($i = 0; $i < 3; $i++) {
        $this->withCredentials()
            ->withUnencryptedCookie('__client', $cookie)
            ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
            ->postJson("https://acme.authn.local/v1/client/sessions/{$sid}/tokens", [])
            ->assertOk();
    }

    $fourth = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$sid}/tokens", []);
    $fourth->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit_exceeded')
        ->assertHeader('Retry-After');
});

it('refuses (and revokes) a token mint for a banned user', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $bs['user']->forceFill(['banned' => true])->save();

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$bs['session']->id}/tokens", []);
    $r->assertStatus(401)->assertJsonPath('errors.0.code', 'user_banned');

    expect(Session::query()->withoutGlobalScopes()->where('id', $bs['session']->id)->first()->status)
        ->toBe('revoked');
});
