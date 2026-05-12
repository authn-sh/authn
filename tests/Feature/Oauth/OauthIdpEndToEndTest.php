<?php

declare(strict_types=1);

use App\Models\AuthorizationGrant;
use App\Models\OauthApplication;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Services\Sessions\SessionTokenIssuer;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Tests\Feature\Http\Bapi\BapiTestSupport;
use Tests\Feature\Http\Me\MeTestSupport;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

/**
 * AU-15 — end-to-end Pest sweep through the full OAuth provider mode
 * ceremony plus the cascade revoke surface.
 */
function withWildcardWebhookEndpoint(string $envId): WebhookEndpoint
{
    return WebhookEndpoint::query()->withoutGlobalScopes()->create([
        'environment_id' => $envId,
        'url' => 'https://hooks.example.test/v07',
        'signing_secret' => str_repeat('a', 32),
        'enabled' => true,
        'enabled_event_types' => ['*'],
    ]);
}

it('runs the confidential client IdP flow end-to-end: authorize (silent reuse) → token → userinfo', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    withWildcardWebhookEndpoint($f['env']->id);
    $app = OauthApplication::factory()->create([
        'environment_id' => $f['env']->id,
        'name' => 'Acme Dashboard',
        'callback_urls' => ['https://app.example.com/oauth/callback'],
        'scopes' => ['openid', 'profile', 'email'],
    ]);
    $secret = OauthApplication::mintClientSecret();
    $app->forceFill(['hashed_client_secret' => $secret['hash']])->save();
    // Pre-existing AuthorizationGrant — `/oauth/authorize` silent-reuses
    // straight into a redirect with code=&state=, no consent screen.
    // (The /oauth/consent/accept dance is covered by AU-9's own test file.)
    AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $bs['user']->id,
        'scopes' => ['openid', 'profile', 'email'],
        'scopes_hash' => AuthorizationGrant::hashScopes(['openid', 'profile', 'email']),
    ]);

    // 1) /oauth/authorize — silent reuse, mints code + redirects to redirect_uri.
    $authorize = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('https://acme.authn.local/oauth/authorize?'.http_build_query([
            'client_id' => $app->client_id,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => 'e2e-state',
            'nonce' => 'e2e-nonce',
        ]));
    $authorize->assertStatus(302);
    $loc = (string) $authorize->headers->get('Location');
    expect($loc)->toStartWith('https://app.example.com/oauth/callback?');
    expect($loc)->toContain('code=oacd_');
    parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
    $code = (string) $q['code'];

    // Grant row visible via /v1/me/authorized-apps.
    $userJwt = app(SessionTokenIssuer::class)->mint($bs['session']->fresh())['jwt'];
    $listed = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$userJwt,
        ])->withoutOpenApiAssertions()
        ->getJson('https://acme.authn.local/v1/me/authorized-apps');
    $listed->assertOk()->assertJsonPath('total_count', 1);

    // 3) /oauth/token — exchange code for access + id + refresh tokens.
    $basic = base64_encode($app->client_id.':'.$secret['plaintext']);
    $token = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.com/oauth/callback',
        ]);
    $token->assertOk();
    $accessToken = (string) $token->json('access_token');
    $refreshToken = (string) $token->json('refresh_token');
    $idToken = (string) $token->json('id_token');

    // id_token validates against the env JWKS (kid header points at the env's
    // active SigningKey).
    $parsed = (new Parser(new JoseEncoder))->parse($idToken);
    expect((string) $parsed->headers()->get('kid'))->not->toBe('');
    expect($parsed->claims()->get('nonce'))->toBe('e2e-nonce');

    // 4) /oauth/userinfo — Bearer-authenticated by access_token, returns claims.
    $userinfo = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$accessToken])
        ->withoutOpenApiAssertions()
        ->getJson('https://acme.authn.local/oauth/userinfo');
    $userinfo->assertOk()
        ->assertJsonPath('sub', $bs['user']->id)
        ->assertJsonPath('email', 'alice@example.com')
        ->assertJsonPath('email_verified', true);

    // 5) /oauth/token grant_type=refresh_token — rotates.
    $rotate = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    $rotate->assertOk();
    expect((string) $rotate->json('refresh_token'))->not->toBe($refreshToken);

    // Re-use of the old refresh token is rejected.
    $reuse = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Basic '.$basic])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    $reuse->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    // token_info introspects the live access_token.
    $introspect = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token_info', ['token' => $accessToken]);
    $introspect->assertOk()->assertJsonPath('active', true);

    // Webhook events fired across the run.
    $types = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->pluck('type')->toArray();
    expect($types)->toContain('oauthToken.issued');
});

it('runs the public PKCE client IdP flow end-to-end via silent-reuse', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $bs = SessionsTestSupport::makeUserWithSession($f['env']);
    $app = OauthApplication::factory()->public()->create([
        'environment_id' => $f['env']->id,
        'callback_urls' => ['https://spa.example/cb'],
        'scopes' => ['openid'],
    ]);
    AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $bs['user']->id,
        'scopes' => ['openid'],
        'scopes_hash' => AuthorizationGrant::hashScopes(['openid']),
    ]);

    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $authorize = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('https://acme.authn.local/oauth/authorize?'.http_build_query([
            'client_id' => $app->client_id,
            'redirect_uri' => 'https://spa.example/cb',
            'response_type' => 'code',
            'scope' => 'openid',
            'state' => 'pkce-state',
            'nonce' => 'pkce-nonce',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));
    $authorize->assertStatus(302);
    parse_str((string) parse_url((string) $authorize->headers->get('Location'), PHP_URL_QUERY), $q);

    // Public client: PKCE verifier, no Basic auth.
    $token = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->withoutOpenApiAssertions()
        ->post('https://acme.authn.local/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $q['code'],
            'redirect_uri' => 'https://spa.example/cb',
            'client_id' => $app->client_id,
            'code_verifier' => $verifier,
        ]);
    $token->assertOk();
    expect($token->json('id_token'))->toBeString();
});

it('cascades authorizationGrant.revoked webhooks when an OauthApplication is deleted via BAPI', function (): void {
    $bapi = BapiTestSupport::bootEnv('cascade');
    withWildcardWebhookEndpoint($bapi['env']->id);
    $app = OauthApplication::factory()->create(['environment_id' => $bapi['env']->id]);
    $user = User::create(['environment_id' => $bapi['env']->id]);
    AuthorizationGrant::factory()->create([
        'environment_id' => $bapi['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($bapi['token']))
        ->deleteJson(BapiTestSupport::url('/oauth-applications/'.$app->id));
    $r->assertNoContent();

    $types = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $bapi['env']->id)
        ->pluck('type')->toArray();
    expect($types)->toContain('oauthApplication.deleted');
    expect($types)->toContain('authorizationGrant.revoked');
});

it('emits oauthApplication.updated with client_secret_rotated: true on rotate-secret', function (): void {
    $bapi = BapiTestSupport::bootEnv('rotate-evt');
    withWildcardWebhookEndpoint($bapi['env']->id);
    $app = OauthApplication::factory()->create(['environment_id' => $bapi['env']->id]);

    $r = $this->withHeaders(BapiTestSupport::headers($bapi['token']))
        ->postJson(BapiTestSupport::url('/oauth-applications/'.$app->id.'/rotate-secret'));
    $r->assertOk();

    $event = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $bapi['env']->id)
        ->where('type', 'oauthApplication.updated')
        ->first();
    expect($event)->not->toBeNull();
    expect($event->data['client_secret_rotated'] ?? false)->toBeTrue();
});

it('emits authorizationGrant.revoked when the user revokes their own grant', function (): void {
    $f = SessionsTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    $grant = AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $auth['user']->id,
    ]);

    $r = $this->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$auth['jwt'],
        ])->withoutOpenApiAssertions()
        ->deleteJson('https://acme.authn.local/v1/me/authorized-apps/'.$grant->id);
    $r->assertOk();

    expect(WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('type', 'authorizationGrant.revoked')
        ->exists())->toBeTrue();
});
