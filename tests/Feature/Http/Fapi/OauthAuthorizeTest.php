<?php

declare(strict_types=1);

use App\Http\Controllers\Fapi\OauthAuthorizeController;
use App\Models\AuthorizationGrant;
use App\Models\OauthApplication;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

function authorizeUrl(array $params): string
{
    return 'https://acme.authn.local/oauth/authorize?'.http_build_query($params);
}

function authorizeBaseParams(OauthApplication $app, string $redirect = 'https://app.example.com/oauth/callback'): array
{
    return [
        'client_id' => $app->client_id,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => 'state-'.bin2hex(random_bytes(4)),
        'nonce' => 'nonce-'.bin2hex(random_bytes(4)),
    ];
}

it('returns 400 oauth_invalid_client for an unknown client_id', function (): void {
    $f = SessionsTestSupport::bootEnv();

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl([
            'client_id' => 'oac_pub_'.str_repeat('Z', 26),
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'response_type' => 'code',
            'scope' => 'openid',
            'state' => 'state',
        ]));

    $r->assertStatus(400)->assertJsonPath('errors.0.code', 'oauth_invalid_client');
});

it('returns 400 oauth_invalid_redirect_uri when redirect_uri is not on the allowlist', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://allowed.example/cb'],
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl([
            'client_id' => $app->client_id,
            'redirect_uri' => 'https://attacker.example/cb',
            'response_type' => 'code',
            'scope' => 'openid',
            'state' => 'state',
        ]));

    $r->assertStatus(400)->assertJsonPath('errors.0.code', 'oauth_invalid_redirect_uri');
});

it('redirects to redirect_uri with error=invalid_scope when scope is not a subset', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => ['openid', 'profile'],
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl(array_merge(authorizeBaseParams($app), ['scope' => 'openid acme.read_billing'])));

    $r->assertStatus(302);
    $loc = (string) $r->headers->get('Location');
    expect($loc)->toStartWith('https://app.example.com/oauth/callback?');
    expect($loc)->toContain('error=invalid_scope');
    expect($loc)->toContain('state=');
});

it('redirects to redirect_uri with error=invalid_request when state is missing', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl([
            'client_id' => $app->client_id,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'response_type' => 'code',
            'scope' => 'openid',
        ]));

    $r->assertStatus(302);
    expect((string) $r->headers->get('Location'))->toContain('error=invalid_request');
});

it('redirects to redirect_uri with error=invalid_request when openid scope lacks nonce', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);

    $params = authorizeBaseParams($app);
    unset($params['nonce']);

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl($params));

    $r->assertStatus(302);
    expect((string) $r->headers->get('Location'))->toContain('error=invalid_request');
});

it('redirects to redirect_uri with error=invalid_request when public client omits PKCE', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->public()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl(authorizeBaseParams($app)));

    $r->assertStatus(302);
    $loc = (string) $r->headers->get('Location');
    expect($loc)->toContain('error=invalid_request');
    expect($loc)->toContain('code_challenge');
});

it('redirects unauthenticated browser to /sign-in?continue_url=...', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl(authorizeBaseParams($app)));

    $r->assertStatus(302);
    $loc = (string) $r->headers->get('Location');
    expect($loc)->toContain('/sign-in');
    expect($loc)->toContain('continue_url=');
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('returns error=login_required when prompt=none and no session', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl(array_merge(authorizeBaseParams($app), ['prompt' => 'none'])));

    $r->assertStatus(302);
    expect((string) $r->headers->get('Location'))->toContain('error=login_required');
});

it('parks the request + redirects to /oauth/consent/{request_id} when user has no covering grant', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl(authorizeBaseParams($app)));

    $r->assertStatus(302);
    $loc = (string) $r->headers->get('Location');
    expect($loc)->toContain('/oauth/consent/');
    $requestId = substr($loc, (int) strrpos($loc, '/') + 1);
    $row = DB::table('oauth_request_contexts')->where('request_id', $requestId)->first();
    expect($row)->not->toBeNull();
    expect($row->oauth_application_id)->toBe($app->id);
});

it('silent-reuses an existing AuthorizationGrant: mints code + 302 back to redirect_uri', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => ['openid', 'profile', 'email'],
    ]);
    AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $bs['user']->id,
        'scopes' => ['openid', 'profile', 'email'],
    ]);

    $params = authorizeBaseParams($app);
    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl($params));

    $r->assertStatus(302);
    $loc = (string) $r->headers->get('Location');
    expect($loc)->toStartWith('https://app.example.com/oauth/callback?');
    expect($loc)->toContain('code=oacd_');
    expect($loc)->toContain('state='.$params['state']);

    // Code was persisted with the matching app + user + scope set.
    parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
    $row = DB::table('oauth_authorization_codes')->where('code', $q['code'])->first();
    expect($row)->not->toBeNull();
    expect($row->oauth_application_id)->toBe($app->id);
    expect($row->user_id)->toBe($bs['user']->id);
});

it('prompt=consent forces the consent screen even when a covering grant exists', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);
    AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $bs['user']->id,
    ]);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl(array_merge(authorizeBaseParams($app), ['prompt' => 'consent'])));

    $r->assertStatus(302);
    expect((string) $r->headers->get('Location'))->toContain('/oauth/consent/');
});

it('returns the redirect target as JSON when Accept: application/json', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);

    $r = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'Accept' => 'application/json',
    ])->withoutOpenApiAssertions()->get(authorizeUrl(authorizeBaseParams($app)));

    $r->assertOk()
        ->assertJsonPath('object', 'oauth_authorize_result');
    expect($r->json('redirect_url'))->toContain('/sign-in');
});

it('persists the parked request context with a 10-minute TTL', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get(authorizeUrl(authorizeBaseParams($app)));

    $loc = (string) $r->headers->get('Location');
    $requestId = substr($loc, (int) strrpos($loc, '/') + 1);
    $row = DB::table('oauth_request_contexts')->where('request_id', $requestId)->first();
    $ttlSeconds = strtotime((string) $row->expires_at) - strtotime((string) $row->created_at);
    expect($ttlSeconds)->toBeGreaterThanOrEqual(OauthAuthorizeController::REQUEST_CONTEXT_TTL_SECONDS - 1);
    expect($ttlSeconds)->toBeLessThanOrEqual(OauthAuthorizeController::REQUEST_CONTEXT_TTL_SECONDS + 1);
});
