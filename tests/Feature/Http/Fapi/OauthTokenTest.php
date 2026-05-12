<?php

declare(strict_types=1);

use App\Http\Controllers\Fapi\OauthTokenController;
use App\Models\AuthorizationGrant;
use App\Models\OauthApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

function plantOauthCode(
    string $envId,
    string $appId,
    string $userId,
    array $scopes = ['openid', 'profile', 'email'],
    string $redirect = 'https://app.example.com/oauth/callback',
    ?string $codeChallenge = null,
    ?string $codeChallengeMethod = null,
    ?string $nonce = 'nonce-x',
    string $state = 'state-x',
    ?int $ttl = null,
): string {
    $code = 'oacd_'.bin2hex(random_bytes(16));
    DB::table('oauth_authorization_codes')->insert([
        'code' => $code,
        'environment_id' => $envId,
        'oauth_application_id' => $appId,
        'user_id' => $userId,
        'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
        'redirect_uri' => $redirect,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => $codeChallengeMethod,
        'nonce' => $nonce,
        'state' => $state,
        'expires_at' => now()->addSeconds($ttl ?? OauthTokenController::ACCESS_TOKEN_TTL_SECONDS),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $code;
}

function withCoveringGrant(string $envId, string $appId, string $userId, array $scopes): void
{
    AuthorizationGrant::factory()->create([
        'environment_id' => $envId,
        'oauth_application_id' => $appId,
        'user_id' => $userId,
        'scopes' => $scopes,
        'scopes_hash' => AuthorizationGrant::hashScopes($scopes),
    ]);
}

it('exchanges authorization_code for confidential client + Basic auth and returns access + id + refresh tokens', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => ['openid', 'profile', 'email'],
    ]);
    // Refresh the persisted hashed_client_secret via mintClientSecret so we
    // have the plaintext to send back on /oauth/token.
    $minted = OauthApplication::mintClientSecret();
    $app->forceFill(['hashed_client_secret' => $minted['hash']])->save();
    $plaintextSecret = $minted['plaintext'];

    withCoveringGrant($f['env']->id, $app->id, $bs['user']->id, ['openid', 'profile', 'email']);
    $code = plantOauthCode($f['env']->id, $app->id, $bs['user']->id);

    $basic = base64_encode($app->client_id.':'.$plaintextSecret);
    $r = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'Authorization' => 'Basic '.$basic,
    ])->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ]);

    $r->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('expires_in', OauthTokenController::ACCESS_TOKEN_TTL_SECONDS);
    expect($r->json('access_token'))->toBeString();
    expect($r->json('id_token'))->toBeString();
    expect($r->json('refresh_token'))->toStartWith('ort_');

    // id_token carries nonce + email + profile claims.
    $parsed = (new Parser(new JoseEncoder))->parse($r->json('id_token'));
    expect($parsed->claims()->get('nonce'))->toBe('nonce-x');
    expect($parsed->claims()->get('aud'))->toContain($app->client_id);
    expect($parsed->claims()->get('email'))->toBe('alice@example.com');
    expect($parsed->claims()->get('email_verified'))->toBeTrue();
    expect($parsed->claims()->get('given_name'))->toBe('Alice');
    expect($parsed->claims()->get('token_use'))->toBe('id');

    // access_token has token_use=access, scope claim, audience pinned.
    $access = (new Parser(new JoseEncoder))->parse($r->json('access_token'));
    expect($access->claims()->get('token_use'))->toBe('access');
    expect($access->claims()->get('scope'))->toBe('openid profile email');
    expect($access->claims()->get('client_id'))->toBe($app->client_id);

    // Authorization code is single-use — second attempt fails.
    $r2 = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ]);
    $r2->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('exchanges authorization_code for public client + PKCE', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id, 'first_name' => 'Bob']);
    $app = OauthApplication::factory()->public()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://spa.example/callback'],
        'scopes' => ['openid'],
    ]);
    withCoveringGrant($f['env']->id, $app->id, $user->id, ['openid']);

    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $code = plantOauthCode(
        $f['env']->id, $app->id, $user->id,
        scopes: ['openid'],
        redirect: 'https://spa.example/callback',
        codeChallenge: $challenge,
        codeChallengeMethod: 'S256',
    );

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://spa.example/callback',
            'client_id' => $app->client_id,
            'code_verifier' => $verifier,
        ]);

    $r->assertOk();
    expect($r->json('access_token'))->toBeString();
});

it('rejects PKCE verifier mismatch with invalid_grant', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    $app = OauthApplication::factory()->public()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://spa.example/callback'],
        'scopes' => ['openid'],
    ]);
    withCoveringGrant($f['env']->id, $app->id, $user->id, ['openid']);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', 'correct-verifier', true)), '+/', '-_'), '=');
    $code = plantOauthCode(
        $f['env']->id, $app->id, $user->id,
        scopes: ['openid'],
        redirect: 'https://spa.example/callback',
        codeChallenge: $challenge,
        codeChallengeMethod: 'S256',
    );

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://spa.example/callback',
            'client_id' => $app->client_id,
            'code_verifier' => 'wrong-verifier',
        ]);

    $r->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('refuses an expired authorization code with invalid_grant', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);
    $secret = OauthApplication::mintClientSecret();
    $app->forceFill(['hashed_client_secret' => $secret['hash']])->save();
    withCoveringGrant($f['env']->id, $app->id, $user->id, ['openid']);

    $code = plantOauthCode(
        $f['env']->id, $app->id, $user->id,
        scopes: ['openid'],
        ttl: -10,
    );
    // Manually move expires_at into the past — `ttl: -10` puts it 10s ago.
    DB::table('oauth_authorization_codes')->where('code', $code)->update(['expires_at' => now()->subMinutes(1)]);

    $basic = base64_encode($app->client_id.':'.$secret['plaintext']);
    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ]);
    $r->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('rotates a refresh_token on grant_type=refresh_token: old revoked, new minted', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => ['openid', 'profile', 'email'],
    ]);
    $secret = OauthApplication::mintClientSecret();
    $app->forceFill(['hashed_client_secret' => $secret['hash']])->save();
    withCoveringGrant($f['env']->id, $app->id, $bs['user']->id, ['openid', 'profile', 'email']);
    $code = plantOauthCode($f['env']->id, $app->id, $bs['user']->id);

    $basic = base64_encode($app->client_id.':'.$secret['plaintext']);
    $first = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ]);
    $first->assertOk();
    $refresh1 = $first->json('refresh_token');

    $second = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh1,
        ]);
    $second->assertOk();
    $refresh2 = $second->json('refresh_token');
    expect($refresh2)->toStartWith('ort_');
    expect($refresh2)->not->toBe($refresh1);

    // First refresh token is now revoked — re-use rejected.
    $reuse = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh1,
        ]);
    $reuse->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('rejects authorization_code when the covering grant has been revoked', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);
    $secret = OauthApplication::mintClientSecret();
    $app->forceFill(['hashed_client_secret' => $secret['hash']])->save();
    $grant = AuthorizationGrant::factory()->revoked()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
        'scopes' => ['openid'],
        'scopes_hash' => AuthorizationGrant::hashScopes(['openid']),
    ]);
    $code = plantOauthCode($f['env']->id, $app->id, $user->id, scopes: ['openid']);

    $basic = base64_encode($app->client_id.':'.$secret['plaintext']);
    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ]);
    $r->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('refuses wrong client_secret with invalid_client', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
    ]);
    $secret = OauthApplication::mintClientSecret();
    $app->forceFill(['hashed_client_secret' => $secret['hash']])->save();
    withCoveringGrant($f['env']->id, $app->id, $user->id, ['openid']);
    $code = plantOauthCode($f['env']->id, $app->id, $user->id, scopes: ['openid']);

    $basic = base64_encode($app->client_id.':wrong-secret');
    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ]);
    $r->assertStatus(401)->assertJsonPath('error', 'invalid_client');
});

it('token_info introspects an active access_token + reports inactive for garbage', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => ['openid', 'profile', 'email'],
    ]);
    $secret = OauthApplication::mintClientSecret();
    $app->forceFill(['hashed_client_secret' => $secret['hash']])->save();
    withCoveringGrant($f['env']->id, $app->id, $bs['user']->id, ['openid', 'profile', 'email']);
    $code = plantOauthCode($f['env']->id, $app->id, $bs['user']->id);

    $basic = base64_encode($app->client_id.':'.$secret['plaintext']);
    $token = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ])->json('access_token');

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token_info', ['token' => $token]);
    $r->assertOk()
        ->assertJsonPath('active', true)
        ->assertJsonPath('client_id', $app->client_id)
        ->assertJsonPath('sub', $bs['user']->id)
        ->assertJsonPath('scope', 'openid profile email');

    $r2 = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token_info', ['token' => 'garbage']);
    $r2->assertOk()->assertJsonPath('active', false);
});

it('rejects unsupported grant_type with 400', function (): void {
    $f = SessionsTestSupport::bootEnv();

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', ['grant_type' => 'password']);

    $r->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
});
