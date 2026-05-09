<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Environment;
use App\Services\Tenancy\BootstrapService;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

function reloadBapiRoutes(): void
{
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    $routingMode = (string) config('authn.routing_mode', 'subdomain');
    $bapiHost = (string) config('authn.bapi_host');

    $bapi = Route::middleware('bapi')->prefix('v1');
    if ($routingMode === 'subdomain') {
        $bapi->domain($bapiHost);
    } else {
        $bapi->prefix('api/v1');
    }
    $bapi->group(base_path('routes/bapi.php'));
}

function plantBootstrap(): array
{
    config([
        'app.url' => 'https://authn.local',
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.bapi_host' => 'api.authn.local',
    ]);
    reloadBapiRoutes();

    $service = app(BootstrapService::class);
    $service->run([
        'admin_email' => 'op@example.com',
        'admin_password' => 'super-secret',
        'workspace_name' => 'Acme Inc',
        'app_url' => 'https://authn.local',
    ]);

    $env = Environment::firstOrFail();
    $apiKey = ApiKey::where('environment_id', $env->id)
        ->where('kind', ApiKey::KIND_SECRET)
        ->firstOrFail();

    $plaintext = 'sk_live_'.str_repeat('z', 32);
    $apiKey->forceFill(['hashed_secret' => hash('sha256', $plaintext), 'last_used_at' => null])->saveQuietly();

    Cache::flush();

    return ['apiKey' => $apiKey->fresh(), 'plaintext' => $plaintext];
}

it('debounces last_used_at updates to once per minute', function (): void {
    ['apiKey' => $apiKey, 'plaintext' => $plaintext] = plantBootstrap();

    expect($apiKey->last_used_at)->toBeNull();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$plaintext,
        'Host' => 'api.authn.local',
    ])->getJson('http://api.authn.local/v1/_ping')->assertOk();

    $first = $apiKey->fresh()->last_used_at;
    expect($first)->not->toBeNull();

    // Second call within the debounce window: last_used_at should NOT advance.
    $this->withHeaders([
        'Authorization' => 'Bearer '.$plaintext,
        'Host' => 'api.authn.local',
    ])->getJson('http://api.authn.local/v1/_ping')->assertOk();

    $second = $apiKey->fresh()->last_used_at;
    expect($second->equalTo($first))->toBeTrue();
});

it('returns 401 when the bearer token is missing', function (): void {
    plantBootstrap();

    $this->withHeader('Host', 'api.authn.local')
        ->getJson('http://api.authn.local/v1/_ping')
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'authentication_invalid');
});

it('returns 401 when the bearer token does not match any api_key', function (): void {
    plantBootstrap();

    $this->withHeaders([
        'Authorization' => 'Bearer sk_live_'.str_repeat('q', 32),
        'Host' => 'api.authn.local',
    ])
        ->getJson('http://api.authn.local/v1/_ping')
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'authentication_invalid');
});

it('returns 401 for a revoked api_key', function (): void {
    ['apiKey' => $apiKey, 'plaintext' => $plaintext] = plantBootstrap();
    $apiKey->forceFill(['revoked_at' => now()])->saveQuietly();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$plaintext,
        'Host' => 'api.authn.local',
    ])
        ->getJson('http://api.authn.local/v1/_ping')
        ->assertUnauthorized();
});

it('refuses publishable keys at the BAPI bearer slot', function (): void {
    plantBootstrap();
    // A publishable key's plaintext is pk_*; ApiKey's `kind` filter ensures
    // we don't accept them here.
    $this->withHeaders([
        'Authorization' => 'Bearer pk_live_'.str_repeat('p', 32),
        'Host' => 'api.authn.local',
    ])
        ->getJson('http://api.authn.local/v1/_ping')
        ->assertUnauthorized();
});
