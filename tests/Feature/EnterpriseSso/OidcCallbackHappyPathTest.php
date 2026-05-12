<?php

declare(strict_types=1);

use App\Auth\Oauth\StateToken;
use App\Models\Client;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SignInAttempt;
use App\Models\Verification;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

function bootEnvForOidcCallback(): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
    ]);
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    Route::middleware('fapi')->domain('{env_slug}.authn.local')->group(base_path('routes/fapi.php'));
    Cache::flush();

    $project = Project::create(['name' => 'P', 'slug' => 'p-'.uniqid()]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => ['https://app.example.com'],
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['env' => $env];
}

it('returns oidc_id_token_invalid when the IdP returns an unverifiable id_token', function (): void {
    $f = bootEnvForOidcCallback();
    $conn = EnterpriseConnection::factory()->oidc()->create([
        'environment_id' => $f['env']->id,
        'oidc_issuer' => 'https://idp.example.com',
        'oidc_discovery_endpoint' => 'https://idp.example.com/.well-known/openid-configuration',
        'oidc_client_id' => 'client-abc',
        'oidc_client_secret' => 'secret-xyz',
    ]);

    // Mock discovery + token + JWKS endpoints.
    Http::fake([
        'idp.example.com/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/oauth/authorize',
            'token_endpoint' => 'https://idp.example.com/oauth/token',
            'jwks_uri' => 'https://idp.example.com/oauth/jwks',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => 'at',
            'id_token' => 'bogus.idtoken.signature',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
        'idp.example.com/oauth/jwks' => Http::response(['keys' => []]),
    ]);

    // Seed a Client + SignInAttempt + Verification + StateToken so the
    // callback finds its parent state.
    $client = Client::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
    ]);
    $attempt = SignInAttempt::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
        'identifier' => 'alice@acme.test',
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => Verification::STRATEGY_ENTERPRISE_SSO,
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addHour(),
        'nonce' => 'nonce-1',
    ]);
    $state = StateToken::mint(
        environmentId: $f['env']->id,
        providerKey: 'enterprise:'.$conn->id,
        verificationId: $verification->id,
        clientId: $client->id,
        attemptId: $attempt->id,
        attemptKind: 'sign_in',
        redirectUrl: 'https://app.example.com/sign-in',
        redirectUrlComplete: 'https://app.example.com/done',
        nonce: 'nonce-1',
        extra: ['conn' => $conn->id, 'cv' => 'verifier-1'],
    );

    $r = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->get('https://acme.authn.local/v1/enterprise-sso-callback?'.http_build_query([
            'code' => 'auth-code-1',
            'state' => $state,
        ]));

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('__authn_error=oidc_id_token_invalid');
    expect($verification->fresh()->status)->toBe(Verification::STATUS_FAILED);
});

it('rejects an OIDC callback with a mismatched env in the state token', function (): void {
    $f = bootEnvForOidcCallback();
    $state = StateToken::mint(
        environmentId: 'env_someoneelse',
        providerKey: 'enterprise:nope',
        verificationId: 'ver_x',
        clientId: 'c',
        attemptId: 'sia_y',
        attemptKind: 'sign_in',
        redirectUrl: 'https://app.example.com/sign-in',
        redirectUrlComplete: null,
        nonce: 'n',
        extra: ['conn' => 'entcon_x', 'cv' => 'v'],
    );

    $r = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->get('https://acme.authn.local/v1/enterprise-sso-callback?'.http_build_query([
            'code' => 'c',
            'state' => $state,
        ]));

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('__authn_error=state_env_mismatch');
});
