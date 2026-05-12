<?php

declare(strict_types=1);

use App\Models\AuthorizationGrant;
use App\Models\OauthApplication;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

function plantOauthRequestContext(string $envId, string $appId, array $params, ?int $ttl = null): string
{
    $requestId = 'oareq_'.bin2hex(random_bytes(16));
    DB::table('oauth_request_contexts')->insert([
        'request_id' => $requestId,
        'environment_id' => $envId,
        'oauth_application_id' => $appId,
        'params' => json_encode($params, JSON_THROW_ON_ERROR),
        'expires_at' => now()->addSeconds($ttl ?? 600),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $requestId;
}

function consentBaseParams(OauthApplication $app, string $redirect = 'https://app.example.com/oauth/callback', string $state = 'state-x'): array
{
    return [
        'client_id' => $app->client_id,
        'redirect_uri' => $redirect,
        'scope' => 'openid profile email',
        'state' => $state,
        'nonce' => 'nonce-x',
        'code_challenge' => '',
        'code_challenge_method' => '',
    ];
}

it('renders the consent screen for a valid request_id + signed-in user', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'name' => 'Acme Dashboard',
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => ['openid', 'profile', 'email'],
    ]);
    $requestId = plantOauthRequestContext($f['env']->id, $app->id, consentBaseParams($app));

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'X-Inertia' => 'true', 'X-Inertia-Version' => '1'])
        ->withoutOpenApiAssertions()
        ->get('https://acme.authn.local/oauth/consent/'.$requestId);

    $r->assertOk()->assertJsonPath('component', 'AccountPortal/Oauth/ConsentScreen');
    expect($r->json('props.application.name'))->toBe('Acme Dashboard');
    expect($r->json('props.scopes.0.name'))->toBe('openid');
    expect($r->json('props.scopes.0.label'))->toBe('Verify your identity');
});

it('redirects to /sign-in when the user has no live session', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    $requestId = plantOauthRequestContext($f['env']->id, $app->id, consentBaseParams($app));

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('https://acme.authn.local/oauth/consent/'.$requestId);

    $r->assertStatus(302);
    expect((string) $r->headers->get('Location'))->toContain('/sign-in?continue_url=');
});

it('returns 404 when the request_id has expired or never existed', function (): void {
    $f = SessionsTestSupport::bootEnv();

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('https://acme.authn.local/oauth/consent/oareq_'.str_repeat('z', 32));
    $r->assertStatus(404);
});

it('accept creates AuthorizationGrant + authorization code, returns redirect to redirect_uri', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => ['openid', 'profile', 'email'],
    ]);
    $requestId = plantOauthRequestContext($f['env']->id, $app->id, consentBaseParams($app));

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Accept' => 'application/json',
        ])
        ->withoutOpenApiAssertions()
        ->postJson('https://acme.authn.local/oauth/consent/'.$requestId.'/accept');

    $r->assertOk()->assertJsonPath('object', 'oauth_consent_result');
    $redirect = (string) $r->json('redirect_url');
    expect($redirect)->toStartWith('https://app.example.com/oauth/callback?');
    expect($redirect)->toContain('code=oacd_');
    expect($redirect)->toContain('state=state-x');

    // Grant persisted with the scope set + scopes_hash.
    $grant = AuthorizationGrant::query()->withoutGlobalScopes()
        ->where('user_id', $bs['user']->id)
        ->where('oauth_application_id', $app->id)
        ->first();
    expect($grant)->not->toBeNull();
    expect($grant->scopes)->toBe(['openid', 'profile', 'email']);

    // Code persisted with the matching app + user + redirect_uri.
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
    $row = DB::table('oauth_authorization_codes')->where('code', $q['code'])->first();
    expect($row)->not->toBeNull();
    expect($row->redirect_uri)->toBe('https://app.example.com/oauth/callback');

    // Request context is burnt — re-using the same accept rides into 404.
    $r2 = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Accept' => 'application/json'])
        ->withoutOpenApiAssertions()
        ->postJson('https://acme.authn.local/oauth/consent/'.$requestId.'/accept');
    $r2->assertStatus(404);
});

it('deny stamps error=access_denied + drops the request context', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);
    $requestId = plantOauthRequestContext($f['env']->id, $app->id, consentBaseParams($app));

    $r = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'Accept' => 'application/json',
    ])->withoutOpenApiAssertions()
        ->postJson('https://acme.authn.local/oauth/consent/'.$requestId.'/deny');

    $r->assertOk()->assertJsonPath('object', 'oauth_consent_result');
    $redirect = (string) $r->json('redirect_url');
    expect($redirect)->toContain('error=access_denied');
    expect($redirect)->toContain('state=state-x');

    expect(DB::table('oauth_request_contexts')->where('request_id', $requestId)->exists())->toBeFalse();
});

it('accept refuses (401) without an active session', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    $requestId = plantOauthRequestContext($f['env']->id, $app->id, consentBaseParams($app));

    $r = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'Accept' => 'application/json',
    ])->withoutOpenApiAssertions()
        ->postJson('https://acme.authn.local/oauth/consent/'.$requestId.'/accept');

    $r->assertStatus(401)->assertJsonPath('errors.0.code', 'no_active_session');
});
