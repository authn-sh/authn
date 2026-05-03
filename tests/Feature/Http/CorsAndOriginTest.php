<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Services\Client\ClientResolver;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function reloadFapiRoutesForCors(): void
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

function corsBoot(array $allowedOrigins = ['https://app.example.com'], string $kind = Environment::KIND_PRODUCTION): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
    ]);
    reloadFapiRoutesForCors();

    $project = Project::create(['name' => 'P', 'slug' => 'p']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => $kind,
        'slug' => 'acme',
        'frontend_api_host' => 'acme.authn.local',
        'allowed_origins' => $allowedOrigins,
    ]);
    (new SigningKeyGenerator)->generate($env);
    $user = User::create(['environment_id' => $env->id]);
    $client = Client::create(['environment_id' => $env->id]);
    $session = Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);

    return ['env' => $env, 'client' => $client, 'session' => $session];
}

/* ------------------------------------------------------------------ CORS preflight */

it('OPTIONS preflight from an allowed origin returns 204 with CORS headers', function (): void {
    corsBoot();

    $response = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'Origin' => 'https://app.example.com',
        'Access-Control-Request-Method' => 'POST',
    ])->json('OPTIONS', 'https://acme.authn.local/v1/client');

    expect($response->getStatusCode())->toBe(204);
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com');
    expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
    expect($response->headers->get('Access-Control-Allow-Methods'))->toContain('POST');
    expect($response->headers->get('Access-Control-Allow-Headers'))->toContain('Authorization');
    expect($response->headers->get('Access-Control-Max-Age'))->toBe('86400');
});

it('OPTIONS preflight from a disallowed origin returns 204 but NO CORS headers', function (): void {
    corsBoot();

    $response = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'Origin' => 'https://attacker.example',
    ])->json('OPTIONS', 'https://acme.authn.local/v1/client');

    expect($response->getStatusCode())->toBe(204);
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('responses to allowed-origin requests carry CORS headers and Vary: Origin', function (): void {
    $f = corsBoot();

    $response = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'Origin' => 'https://app.example.com',
    ])->getJson('https://acme.authn.local/v1/environment');

    $response->assertOk();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com');
    expect($response->headers->get('Vary'))->toContain('Origin');
});

/* ------------------------------------------------------------------ Origin enforcement */

it('state-changing requests from an allowed origin are accepted', function (): void {
    $f = corsBoot();
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
        ])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertOk();
});

it('state-changing requests from a disallowed origin are rejected with origin_invalid', function (): void {
    $f = corsBoot();
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $response = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://attacker.example',
        ])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens");

    $response->assertForbidden()
        ->assertJsonPath('errors.0.code', 'origin_invalid')
        ->assertJsonPath('errors.0.meta.origin', 'https://attacker.example')
        ->assertJsonPath('errors.0.meta.allowed', ['https://app.example.com']);
});

it('state-changing requests with no Origin and no Bearer token are rejected', function (): void {
    $f = corsBoot();
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeader('Host', 'acme.authn.local')
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'origin_invalid');
});

it('state-changing requests with a Bearer token bypass the Origin check', function (): void {
    $f = corsBoot();
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    // Bearer token is sufficient — Origin missing is OK on the bearer path.
    // ResolveClientFromCookie still requires the cookie for the session lookup
    // route, so we keep the cookie too.
    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Authorization' => 'Bearer device_token_placeholder',
        ])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertOk();
});

it('falls back to Referer host when Origin is absent', function (): void {
    $f = corsBoot();
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Referer' => 'https://app.example.com/some/path',
        ])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertOk();
});

it('GET /v1/environment is publicly accessible from any origin', function (): void {
    corsBoot(allowedOrigins: []);

    $this->withHeaders(['Host' => 'acme.authn.local', 'Origin' => 'https://anywhere.example'])
        ->getJson('https://acme.authn.local/v1/environment')
        ->assertOk();
});

it('GET /v1/client is publicly accessible from any origin', function (): void {
    corsBoot(allowedOrigins: []);

    $this->withHeaders(['Host' => 'acme.authn.local'])
        ->getJson('https://acme.authn.local/v1/client')
        ->assertOk();
});

it('default ports are normalised on both sides', function (): void {
    $f = corsBoot(['https://app.example.com:443']);
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',  // no explicit port
        ])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertOk();
});

it('honours wildcard localhost patterns in development envs', function (): void {
    $f = corsBoot(['http://localhost:*'], Environment::KIND_DEVELOPMENT);
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'http://localhost:5173',
        ])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertOk();
});

it('does NOT honour wildcard localhost patterns in production envs', function (): void {
    $f = corsBoot(['http://localhost:*'], Environment::KIND_PRODUCTION);
    $cookie = app(ClientResolver::class)->mintCookieValue($f['client']);

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'http://localhost:5173',
        ])
        ->postJson("https://acme.authn.local/v1/client/sessions/{$f['session']->id}/tokens")
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'origin_invalid');
});
