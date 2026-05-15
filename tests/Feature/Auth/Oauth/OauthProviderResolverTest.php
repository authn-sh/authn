<?php

declare(strict_types=1);

use App\Auth\Oauth\Exceptions\OauthDiscoveryFailedException;
use App\Auth\Oauth\OauthProviderResolver;
use App\Auth\Oauth\PresetRegistry;
use App\Models\Environment;
use App\Models\OauthProvider;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Tests\Support\OauthProviderFixtures;

function makeEnvForResolver(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

function blankPresetProvider(Environment $env, string $key): OauthProvider
{
    return OauthProviderFixtures::blankPreset($env, $key);
}

it('hydrates a Google preset row with the canonical endpoints + scopes', function (): void {
    $env = makeEnvForResolver();
    $row = blankPresetProvider($env, 'google');

    $resolved = app(OauthProviderResolver::class)->resolve($row);

    expect($resolved->authorizationEndpoint)->toBe('https://accounts.google.com/o/oauth2/v2/auth');
    expect($resolved->tokenEndpoint)->toBe('https://oauth2.googleapis.com/token');
    expect($resolved->userinfoEndpoint)->toBe('https://openidconnect.googleapis.com/v1/userinfo');
    expect($resolved->jwksUri)->toBe('https://www.googleapis.com/oauth2/v3/certs');
    expect($resolved->scopes)->toBe(['openid', 'email', 'profile']);
    expect($resolved->attributeMapping)->toMatchArray([
        'email' => 'email',
        'first_name' => 'given_name',
        'last_name' => 'family_name',
    ]);
    expect($resolved->idTokenSigningAlgs)->toBe(['RS256']);
    expect($resolved->userinfoMethod)->toBe('GET');
});

it('hydrates a GitHub preset row (non-OIDC, no jwks_uri)', function (): void {
    $env = makeEnvForResolver();
    $row = blankPresetProvider($env, 'github');

    $resolved = app(OauthProviderResolver::class)->resolve($row);

    expect($resolved->authorizationEndpoint)->toBe('https://github.com/login/oauth/authorize');
    expect($resolved->jwksUri)->toBeNull();
    expect($resolved->scopes)->toBe(['read:user', 'user:email']);
    expect($resolved->attributeMapping['username'] ?? null)->toBe('login');
});

it('hydrates Apple preset with form_post + ES256', function (): void {
    $env = makeEnvForResolver();
    $row = blankPresetProvider($env, 'apple');

    $resolved = app(OauthProviderResolver::class)->resolve($row);

    expect($resolved->additionalAuthorizationParams['response_mode'] ?? null)->toBe('form_post');
    expect($resolved->idTokenSigningAlgs)->toBe(['ES256']);
    expect($resolved->userinfoMethod)->toBe('POST');
});

it('hydrates Microsoft preset with the common-tenant endpoints', function (): void {
    $env = makeEnvForResolver();
    $row = blankPresetProvider($env, 'microsoft');

    $resolved = app(OauthProviderResolver::class)->resolve($row);

    expect($resolved->authorizationEndpoint)->toContain('login.microsoftonline.com/common');
    expect($resolved->tokenEndpoint)->toContain('login.microsoftonline.com/common');
    expect($resolved->scopes)->toBe(['openid', 'email', 'profile']);
});

it('lets stored values override preset defaults', function (): void {
    $env = makeEnvForResolver();
    $row = blankPresetProvider($env, 'google');
    $row->forceFill([
        'scopes' => ['openid', 'email'],
        'authorization_endpoint' => 'https://example.test/authorize',
    ])->save();

    $resolved = app(OauthProviderResolver::class)->resolve($row->refresh());

    expect($resolved->scopes)->toBe(['openid', 'email']);
    expect($resolved->authorizationEndpoint)->toBe('https://example.test/authorize');
    expect($resolved->tokenEndpoint)->toBe('https://oauth2.googleapis.com/token');
});

it('discovers OIDC endpoints via the well-known document', function (): void {
    Http::fake([
        'https://idp.test/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.test/authorize',
            'token_endpoint' => 'https://idp.test/token',
            'userinfo_endpoint' => 'https://idp.test/userinfo',
            'jwks_uri' => 'https://idp.test/jwks',
            'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
        ], 200),
    ]);

    $endpoints = app(OauthProviderResolver::class)->discoverEndpoints('https://idp.test');

    expect($endpoints['authorization_endpoint'])->toBe('https://idp.test/authorize');
    expect($endpoints['userinfo_endpoint'])->toBe('https://idp.test/userinfo');
    expect($endpoints['id_token_signing_algs'])->toBe(['RS256', 'ES256']);
});

it('caches discovery on the row and reuses it within the TTL', function (): void {
    $env = makeEnvForResolver();
    $row = OauthProvider::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_CUSTOM_OIDC,
        'provider_key' => 'idp_one',
        'name' => 'IdP One',
        'client_id' => 'cid',
        'encrypted_client_secret' => 'sec',
        'issuer' => 'https://idp.test',
    ]);

    Http::fake([
        'https://idp.test/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.test/authorize',
            'token_endpoint' => 'https://idp.test/token',
            'userinfo_endpoint' => 'https://idp.test/userinfo',
        ], 200),
    ]);

    $resolver = app(OauthProviderResolver::class);

    $resolver->resolve($row->fresh());
    $resolver->resolve($row->fresh());

    Http::assertSentCount(1);
    expect($row->fresh()->discovery_cached_at)->not->toBeNull();
});

it('refreshes discovery after the TTL elapses', function (): void {
    $env = makeEnvForResolver();
    $row = OauthProvider::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_CUSTOM_OIDC,
        'provider_key' => 'idp_two',
        'name' => 'IdP Two',
        'client_id' => 'cid',
        'encrypted_client_secret' => 'sec',
        'issuer' => 'https://idp.test',
        'authorization_endpoint' => 'https://idp.test/authorize',
        'token_endpoint' => 'https://idp.test/token',
        'userinfo_endpoint' => 'https://idp.test/userinfo',
        'discovery_cached_at' => now()->subSeconds(OauthProviderResolver::DISCOVERY_TTL_SECONDS + 60),
    ]);

    Http::fake([
        'https://idp.test/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.test/authorize-v2',
            'token_endpoint' => 'https://idp.test/token-v2',
            'userinfo_endpoint' => 'https://idp.test/userinfo-v2',
        ], 200),
    ]);

    $resolved = app(OauthProviderResolver::class)->resolve($row->fresh());

    expect($resolved->authorizationEndpoint)->toBe('https://idp.test/authorize-v2');
    expect($row->fresh()->authorization_endpoint)->toBe('https://idp.test/authorize-v2');
});

it('throws OauthDiscoveryFailedException on HTTP 5xx', function (): void {
    Http::fake([
        'https://idp.test/.well-known/openid-configuration' => Http::response('upstream blew up', 502),
    ]);

    expect(fn () => app(OauthProviderResolver::class)->discoverEndpoints('https://idp.test'))
        ->toThrow(OauthDiscoveryFailedException::class, 'HTTP 502');
});

it('rejects discovery responses that are missing required fields', function (): void {
    Http::fake([
        'https://idp.test/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.test/authorize',
            // missing token_endpoint + userinfo_endpoint
        ], 200),
    ]);

    expect(fn () => app(OauthProviderResolver::class)->discoverEndpoints('https://idp.test'))
        ->toThrow(OauthDiscoveryFailedException::class, 'token_endpoint');
});

it('falls back to stored endpoints on transient discovery failure', function (): void {
    $env = makeEnvForResolver();
    $row = OauthProvider::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_CUSTOM_OIDC,
        'provider_key' => 'idp_three',
        'name' => 'IdP Three',
        'client_id' => 'cid',
        'encrypted_client_secret' => 'sec',
        'issuer' => 'https://idp.test',
        'authorization_endpoint' => 'https://cached.test/authorize',
        'token_endpoint' => 'https://cached.test/token',
        'userinfo_endpoint' => 'https://cached.test/userinfo',
    ]);

    Http::fake([
        'https://idp.test/.well-known/openid-configuration' => Http::response('upstream down', 503),
    ]);

    $resolved = app(OauthProviderResolver::class)->resolve($row);

    expect($resolved->authorizationEndpoint)->toBe('https://cached.test/authorize');
});

it('applies the default OIDC attribute mapping when none is stored', function (): void {
    $env = makeEnvForResolver();
    $row = OauthProvider::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_CUSTOM_OIDC,
        'provider_key' => 'idp_four',
        'name' => 'IdP Four',
        'client_id' => 'cid',
        'encrypted_client_secret' => 'sec',
        'authorization_endpoint' => 'https://x.test/authorize',
        'token_endpoint' => 'https://x.test/token',
        'userinfo_endpoint' => 'https://x.test/userinfo',
    ]);

    $resolved = app(OauthProviderResolver::class)->resolve($row);

    expect($resolved->attributeMapping)->toBe(OauthProviderResolver::DEFAULT_OIDC_ATTRIBUTE_MAPPING);
});

it('does not auto-seed preset rows on environment creation (#284)', function (): void {
    $env = makeEnvForResolver('seedme');

    $rows = OauthProvider::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->get();

    expect($rows)->toHaveCount(0);
});

it('OauthProvider::computeRedirectUri() builds the canonical callback URL', function (): void {
    config()->set('authn.app_host', 'authn.sh');
    config()->set('authn.app_scheme', 'https');
    config()->set('authn.routing_mode', 'subdomain');
    config()->set('authn.app_port_suffix', '');

    $env = makeEnvForResolver('acme');
    $row = blankPresetProvider($env->refresh(), 'google');

    expect($row->computeRedirectUri())->toBe('https://acme.authn.sh/v1/oauth-callback/google');
    expect($row->redirect_uri)->toBe('https://acme.authn.sh/v1/oauth-callback/google');
});

it('PresetRegistry exposes the canonical preset keys in registration order', function (): void {
    $registry = app(PresetRegistry::class);
    expect($registry->keys())->toBe([
        'google', 'github', 'apple', 'microsoft',
        'discord', 'facebook', 'linkedin', 'x', 'gitlab', 'slack',
    ]);
});
