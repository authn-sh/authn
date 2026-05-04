<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Services\Client\ClientResolver;
use App\Services\Keys\SigningKeyGenerator;
use App\Services\Sessions\SessionTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function reloadFapiRoutes(): void
{
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    $appHost = (string) config('authn.app_host');
    $fapi = Route::middleware('fapi');
    if ((string) config('authn.routing_mode') === 'subdomain') {
        $fapi->domain('{env_slug}.'.$appHost);
    } else {
        $fapi->prefix('{env_slug}');
    }
    $fapi->group(base_path('routes/fapi.php'));
}

function bootEnv(): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
    ]);
    reloadFapiRoutes();

    $project = Project::create(['name' => 'P', 'slug' => 'p']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => ['https://app.example.com'],
    ]);
    (new SigningKeyGenerator)->generate($env);
    $user = User::create(['environment_id' => $env->id]);
    $client = Client::create(['environment_id' => $env->id]);
    $session = Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);

    return ['env' => $env, 'client' => $client, 'user' => $user, 'session' => $session];
}

it('mints a __session JWT when the cookie matches the device', function (): void {
    $f = bootEnv();
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $response = $this->withUnencryptedCookie('__client', $cookie)
        ->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => 'https://app.example.com'])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens");

    $response->assertOk()->assertJsonStructure(['jwt', 'expires_at', 'kid']);

    $jwt = $response->json('jwt');
    expect(app(SessionTokenVerifier::class)->verify($jwt, $f['env']->fresh()))
        ->not->toBeNull();
});

it('rejects the request with 401 when the __client cookie is missing', function (): void {
    $f = bootEnv();

    $this->withHeaders(['Host' => 'acme.authn.local', 'Origin' => 'https://app.example.com'])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'client_not_found');
});

it('returns 404 when the session belongs to a different client', function (): void {
    $f = bootEnv();
    $otherClient = Client::create(['environment_id' => $f['env']->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($otherClient);

    $this->withUnencryptedCookie('__client', $cookie)
        ->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => 'https://app.example.com'])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'session_not_found');
});

it('returns 401 session_revoked for a session in a non-live status', function (): void {
    $f = bootEnv();
    $f['session']->status = Session::STATUS_REVOKED;
    $f['session']->save();

    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $this->withUnencryptedCookie('__client', $cookie)
        ->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => 'https://app.example.com'])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'session_revoked');
});

it('returns 404 template_not_found for any non-default template', function (): void {
    $f = bootEnv();
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $this->withUnencryptedCookie('__client', $cookie)
        ->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => 'https://app.example.com'])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens/supabase")
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'template_not_found');
});

it('serves JWKS at /.well-known/jwks.json with cache headers', function (): void {
    $f = bootEnv();

    $response = $this->withHeaders(['Host' => 'acme.authn.local', 'Origin' => 'https://app.example.com'])
        ->getJson('https://acme.authn.local/.well-known/jwks.json');

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('max-age=300');
    expect($response->json('keys'))->toHaveCount(1);
    expect($response->json('keys.0.kty'))->toBe('RSA');
    expect($response->json('keys.0.alg'))->toBe('RS256');
});

it('serves OIDC discovery at /.well-known/openid-configuration', function (): void {
    $f = bootEnv();

    $response = $this->withHeaders(['Host' => 'acme.authn.local', 'Origin' => 'https://app.example.com'])
        ->getJson('https://acme.authn.local/.well-known/openid-configuration');

    $response->assertOk()->assertJson([
        'issuer' => 'https://acme.authn.local',
        'jwks_uri' => 'https://acme.authn.local/.well-known/jwks.json',
        'id_token_signing_alg_values_supported' => ['RS256'],
    ]);
});
