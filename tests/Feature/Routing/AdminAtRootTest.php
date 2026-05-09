<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;


function reloadAdminRoutes(): void
{
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    $appHost = (string) config('authn.app_host');
    $routingMode = (string) config('authn.routing_mode', 'subdomain');

    if ($routingMode === 'subdomain') {
        Route::middleware('fapi')->domain($appHost)->group(base_path('routes/fapi.php'));
        Route::middleware('fapi')->domain('{env_slug}.'.$appHost)->group(base_path('routes/fapi.php'));
    } else {
        Route::middleware('fapi')->group(base_path('routes/fapi.php'));
        Route::middleware('fapi')->prefix('{env_slug}')->group(base_path('routes/fapi.php'));
    }
}

function bootAdminAt(string $appHost = 'authn.local', string $mode = 'subdomain'): array
{
    config([
        'authn.routing_mode' => $mode,
        'authn.app_host' => $appHost,
        'authn.app_scheme' => 'http',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => $mode === 'subdomain' ? 'api.'.$appHost : $appHost,
        'authn.dashboard_host' => $mode === 'subdomain' ? 'dashboard.'.$appHost : $appHost,
        'app.url' => 'http://'.$appHost,
    ]);
    reloadAdminRoutes();

    $project = Project::create(['name' => 'authn.sh admin', 'slug' => Project::SYSTEM_SLUG, 'is_system' => true]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => Project::SYSTEM_SLUG,
        // The reserved `_admin` env carries no routing_label; it's
        // special-cased to the bare app host in both routing modes.
        'routing_label' => null,
        'allowed_origins' => [],
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['project' => $project, 'env' => $env];
}

it('subdomain mode: GET https://<APP_HOST>/sign-in serves the _admin Account Portal', function (): void {
    bootAdminAt('authn.local', 'subdomain');

    $r = $this->withHeaders([
        'Host' => 'authn.local',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
        'Accept' => 'application/json',
    ])->getJson('http://authn.local/sign-in');

    $r->assertOk()->assertJsonPath('component', 'AccountPortal/SignIn');
});

it('subdomain mode: tenant slug at <slug>.<APP_HOST> still works', function (): void {
    $f = bootAdminAt('authn.local', 'subdomain');
    // Provision a separate tenant.
    $tenantProject = Project::create(['name' => 'Acme', 'slug' => 'acme']);
    Environment::create([
        'project_id' => $tenantProject->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);
    (new SigningKeyGenerator)->generate(Environment::query()->withoutGlobalScopes()->where('slug', 'acme')->first());

    $r = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
        'Accept' => 'application/json',
    ])->getJson('http://acme.authn.local/sign-in');

    $r->assertOk()->assertJsonPath('component', 'AccountPortal/SignIn');
});

it('path mode: GET http://<APP_HOST>/sign-in serves the _admin Account Portal', function (): void {
    bootAdminAt('localhost', 'path');

    $r = $this->withHeaders([
        'Host' => 'localhost',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
        'Accept' => 'application/json',
    ])->getJson('http://localhost/sign-in');

    $r->assertOk()->assertJsonPath('component', 'AccountPortal/SignIn');
});

it('path mode: tenant slug at /<slug>/sign-in still works', function (): void {
    bootAdminAt('localhost', 'path');
    $tenantProject = Project::create(['name' => 'Acme', 'slug' => 'acme']);
    Environment::create([
        'project_id' => $tenantProject->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);
    (new SigningKeyGenerator)->generate(Environment::query()->withoutGlobalScopes()->where('slug', 'acme')->first());

    $r = $this->withHeaders([
        'Host' => 'localhost',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
        'Accept' => 'application/json',
    ])->getJson('http://localhost/acme/sign-in');

    $r->assertOk()->assertJsonPath('component', 'AccountPortal/SignIn');
});
