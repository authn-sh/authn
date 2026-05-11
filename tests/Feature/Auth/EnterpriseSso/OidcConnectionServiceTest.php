<?php

declare(strict_types=1);

use App\Auth\EnterpriseSso\OidcConnectionService;
use App\Auth\EnterpriseSso\OidcDiscoveryException;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function makeOidcEnv(string $slug = 'acme'): Environment
{
    config([
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.routing_mode' => 'subdomain',
        'authn.app_port_suffix' => '',
    ]);
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

function makeOidcConnection(Environment $env, array $overrides = []): EnterpriseConnection
{
    return EnterpriseConnection::factory()->oidc()->create(array_merge([
        'environment_id' => $env->id,
        'oidc_issuer' => 'https://idp.example.com',
        'oidc_discovery_endpoint' => 'https://idp.example.com/.well-known/openid-configuration',
        'oidc_client_id' => 'client-abc',
        'oidc_client_secret' => 'secret-xyz',
        'oidc_scopes' => ['openid', 'email', 'profile'],
    ], $overrides));
}

beforeEach(function (): void {
    Cache::flush();
});

it('fetches and caches discovery, parsing the endpoints + signing algs', function (): void {
    $env = makeOidcEnv();
    $conn = makeOidcConnection($env);

    Http::fake([
        'idp.example.com/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/oauth/authorize',
            'token_endpoint' => 'https://idp.example.com/oauth/token',
            'userinfo_endpoint' => 'https://idp.example.com/oauth/userinfo',
            'jwks_uri' => 'https://idp.example.com/oauth/jwks',
            'id_token_signing_alg_values_supported' => ['RS256', 'RS512'],
        ]),
    ]);

    $service = new OidcConnectionService;
    $first = $service->discover($conn);
    expect($first['authorization_endpoint'])->toBe('https://idp.example.com/oauth/authorize');
    expect($first['token_endpoint'])->toBe('https://idp.example.com/oauth/token');
    expect($first['jwks_uri'])->toBe('https://idp.example.com/oauth/jwks');
    expect($first['id_token_signing_algs'])->toBe(['RS256', 'RS512']);

    // Cache hit on second call — second fake unneeded.
    $second = $service->discover($conn);
    expect($second)->toBe($first);
    Http::assertSentCount(1);
});

it('throws OidcDiscoveryException when the IdP returns 5xx', function (): void {
    $env = makeOidcEnv();
    $conn = makeOidcConnection($env);

    Http::fake([
        'idp.example.com/.well-known/openid-configuration' => Http::response('server error', 502),
    ]);

    expect(fn () => (new OidcConnectionService)->discover($conn))
        ->toThrow(OidcDiscoveryException::class);
});

it('throws OidcDiscoveryException when the response is missing required endpoints', function (): void {
    $env = makeOidcEnv();
    $conn = makeOidcConnection($env);

    Http::fake([
        'idp.example.com/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/oauth/authorize',
            // token_endpoint missing
        ]),
    ]);

    expect(fn () => (new OidcConnectionService)->discover($conn))
        ->toThrow(OidcDiscoveryException::class);
});

it('builds an authorize URL with PKCE-S256 challenge, state, nonce and configured scopes', function (): void {
    $env = makeOidcEnv();
    $conn = makeOidcConnection($env, ['oidc_scopes' => ['openid', 'email', 'groups']]);

    Http::fake([
        'idp.example.com/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/oauth/authorize',
            'token_endpoint' => 'https://idp.example.com/oauth/token',
            'jwks_uri' => 'https://idp.example.com/oauth/jwks',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
    ]);

    $result = (new OidcConnectionService)->buildAuthorizeUrl($conn, state: 'sid:sia_abc', nonce: 'n-xyz');
    expect($result['authorize_url'])->toStartWith('https://idp.example.com/oauth/authorize?');
    parse_str(parse_url($result['authorize_url'], PHP_URL_QUERY) ?: '', $query);
    expect($query['response_type'])->toBe('code');
    expect($query['client_id'])->toBe('client-abc');
    expect($query['redirect_uri'])->toBe('https://acme.authn.local/v1/enterprise-sso-callback/'.$conn->id);
    expect($query['state'])->toBe('sid:sia_abc');
    expect($query['nonce'])->toBe('n-xyz');
    expect($query['scope'])->toBe('openid email groups');
    expect($query['code_challenge_method'])->toBe('S256');
    expect($query['code_challenge'])->not->toBeEmpty();
    expect(strlen($result['code_verifier']))->toBeGreaterThan(20);
});

it('exchanges an authorization code for tokens and returns the relevant fields', function (): void {
    $env = makeOidcEnv();
    $conn = makeOidcConnection($env);

    Http::fake([
        'idp.example.com/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/oauth/authorize',
            'token_endpoint' => 'https://idp.example.com/oauth/token',
            'jwks_uri' => 'https://idp.example.com/oauth/jwks',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => 'at-tok',
            'id_token' => 'id-tok',
            'refresh_token' => 'rt-tok',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
    ]);

    $tokens = (new OidcConnectionService)->exchangeCode($conn, code: 'auth-code-1', codeVerifier: 'verifier-1');
    expect($tokens['access_token'])->toBe('at-tok');
    expect($tokens['id_token'])->toBe('id-tok');
    expect($tokens['refresh_token'])->toBe('rt-tok');
    expect($tokens['token_type'])->toBe('Bearer');
    expect($tokens['expires_in'])->toBe(3600);
});

it('returns null from verifyIdToken when the JWS signature is bogus', function (): void {
    $env = makeOidcEnv();
    $conn = makeOidcConnection($env);

    Http::fake([
        'idp.example.com/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/oauth/authorize',
            'token_endpoint' => 'https://idp.example.com/oauth/token',
            'jwks_uri' => 'https://idp.example.com/oauth/jwks',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
        'idp.example.com/oauth/jwks' => Http::response(['keys' => []]),
    ]);

    // garbage id_token
    expect((new OidcConnectionService)->verifyIdToken($conn, 'header.payload.sig', 'expected-nonce'))->toBeNull();
});
