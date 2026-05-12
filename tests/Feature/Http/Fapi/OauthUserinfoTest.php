<?php

declare(strict_types=1);

use App\Http\Controllers\Fapi\OauthTokenController;
use App\Models\AuthorizationGrant;
use App\Models\OauthApplication;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

function userinfoAccessToken(array $scopes, ?array $bsOverride = null): array
{
    $f = SessionsTestSupport::bootEnv();
    $bs = $bsOverride ?? SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => $scopes,
    ]);
    $secret = OauthApplication::mintClientSecret();
    $app->forceFill(['hashed_client_secret' => $secret['hash']])->save();

    AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $bs['user']->id,
        'scopes' => $scopes,
        'scopes_hash' => AuthorizationGrant::hashScopes($scopes),
    ]);

    $code = 'oacd_'.bin2hex(random_bytes(16));
    DB::table('oauth_authorization_codes')->insert([
        'code' => $code,
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $bs['user']->id,
        'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
        'redirect_uri' => 'https://app.example.com/oauth/callback',
        'expires_at' => now()->addSeconds(OauthTokenController::ACCESS_TOKEN_TTL_SECONDS),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $basic = base64_encode($app->client_id.':'.$secret['plaintext']);
    $r = test()->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ]);
    $r->assertOk();

    return ['f' => $f, 'bs' => $bs, 'access_token' => $r->json('access_token')];
}

it('returns sub only when only the openid scope was granted', function (): void {
    $ctx = userinfoAccessToken(['openid']);

    $r = test()->withHeaders([
        'Host' => 'acme.authn.local',
        'Authorization' => 'Bearer '.$ctx['access_token'],
    ])->withoutOpenApiAssertions()
        ->getJson('https://acme.authn.local/oauth/userinfo');

    $r->assertOk()
        ->assertJsonPath('sub', $ctx['bs']['user']->id);
    expect($r->json())->not->toHaveKey('email');
    expect($r->json())->not->toHaveKey('given_name');
});

it('returns profile claims when profile scope is granted', function (): void {
    $ctx = userinfoAccessToken(['openid', 'profile']);

    $r = test()->withHeaders([
        'Host' => 'acme.authn.local',
        'Authorization' => 'Bearer '.$ctx['access_token'],
    ])->withoutOpenApiAssertions()
        ->getJson('https://acme.authn.local/oauth/userinfo');

    $r->assertOk()
        ->assertJsonPath('given_name', 'Alice')
        ->assertJsonPath('family_name', '')
        ->assertJsonPath('name', 'Alice');
    expect($r->json())->not->toHaveKey('email');
});

it('returns email + email_verified when email scope is granted', function (): void {
    $ctx = userinfoAccessToken(['openid', 'email']);

    $r = test()->withHeaders([
        'Host' => 'acme.authn.local',
        'Authorization' => 'Bearer '.$ctx['access_token'],
    ])->withoutOpenApiAssertions()
        ->getJson('https://acme.authn.local/oauth/userinfo');

    $r->assertOk()
        ->assertJsonPath('email', 'alice@example.com')
        ->assertJsonPath('email_verified', true);
});

it('refuses without a Bearer token', function (): void {
    $f = SessionsTestSupport::bootEnv();

    $r = test()->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->getJson('https://acme.authn.local/oauth/userinfo');

    $r->assertStatus(401)->assertJsonPath('error', 'missing_token');
});

it('refuses an unknown / malformed Bearer token', function (): void {
    $f = SessionsTestSupport::bootEnv();

    $r = test()->withHeaders([
        'Host' => 'acme.authn.local',
        'Authorization' => 'Bearer not-a-real-token',
    ])->withoutOpenApiAssertions()
        ->getJson('https://acme.authn.local/oauth/userinfo');

    $r->assertStatus(401)->assertJsonPath('error', 'invalid_token');
});

it('refuses an access_token whose scope set lacks openid (insufficient_scope)', function (): void {
    // Mint an access_token with a custom scope only — userinfo refuses.
    // Easiest path: grant the openid + custom scope, then strip openid from
    // the access-token claim. Since the controller signs the JWT itself we
    // can't easily forge one — use the fact that minting with just `profile`
    // (no openid) is rejected by AU-7's invalid_grant. Instead, mint with
    // openid + profile + email, then build a stub assertion via a fresh app
    // whose grant doesn't include openid. We assert at the userinfo level
    // by trimming openid out of the access token's scope via direct token
    // forge: not feasible without breaking sign — so we test the
    // controller branch via a path the spec actually exposes.
    //
    // Reality check: an access_token issued without `openid` is a perfectly
    // valid OAuth access token, just not an OIDC one. /oauth/userinfo
    // specifically asks for OIDC, so we refuse. Mint via the
    // authorization-code path with scopes=['profile'] and a covering grant.
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => ['profile'],
    ]);
    $secret = OauthApplication::mintClientSecret();
    $app->forceFill(['hashed_client_secret' => $secret['hash']])->save();
    AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $bs['user']->id,
        'scopes' => ['profile'],
        'scopes_hash' => AuthorizationGrant::hashScopes(['profile']),
    ]);
    $code = 'oacd_'.bin2hex(random_bytes(16));
    DB::table('oauth_authorization_codes')->insert([
        'code' => $code,
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $bs['user']->id,
        'scopes' => json_encode(['profile'], JSON_THROW_ON_ERROR),
        'redirect_uri' => 'https://app.example.com/oauth/callback',
        'expires_at' => now()->addSeconds(OauthTokenController::ACCESS_TOKEN_TTL_SECONDS),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $basic = base64_encode($app->client_id.':'.$secret['plaintext']);
    $minted = test()->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ]);
    $minted->assertOk();
    expect($minted->json('id_token'))->toBeNull();
    $accessToken = (string) $minted->json('access_token');

    $r = test()->withHeaders([
        'Host' => 'acme.authn.local',
        'Authorization' => 'Bearer '.$accessToken,
    ])->withoutOpenApiAssertions()
        ->getJson('https://acme.authn.local/oauth/userinfo');

    $r->assertStatus(401)->assertJsonPath('error', 'insufficient_scope');
});

it('exposes the v0.7 IdP endpoints in /.well-known/openid-configuration', function (): void {
    $f = SessionsTestSupport::bootEnv();

    $r = test()->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->getJson('https://acme.authn.local/.well-known/openid-configuration');

    $r->assertOk()
        ->assertJsonPath('issuer', 'https://acme.authn.local')
        ->assertJsonPath('authorization_endpoint', 'https://acme.authn.local/oauth/authorize')
        ->assertJsonPath('token_endpoint', 'https://acme.authn.local/oauth/token')
        ->assertJsonPath('userinfo_endpoint', 'https://acme.authn.local/oauth/userinfo')
        ->assertJsonPath('introspection_endpoint', 'https://acme.authn.local/oauth/token_info');
    expect($r->json('grant_types_supported'))->toBe(['authorization_code', 'refresh_token']);
    expect($r->json('code_challenge_methods_supported'))->toBe(['S256']);
    expect($r->json('scopes_supported'))->toContain('openid', 'profile', 'email');
    expect($r->headers->get('Cache-Control'))->toContain('public');
});
