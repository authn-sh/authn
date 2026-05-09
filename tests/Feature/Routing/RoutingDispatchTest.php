<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Tenancy\BootstrapService;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

/**
 * Reload the route stack against the current routing-mode config. Pest
 * tests share an Application instance per test, but the route loader runs
 * at boot — so we re-register all four groups manually after flipping the
 * config.
 */
function reloadRoutes(): void
{
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    $routingMode = (string) config('authn.routing_mode', 'subdomain');
    $bapiHost = (string) config('authn.bapi_host');
    $dashboardHost = (string) config('authn.dashboard_host');
    $appHost = (string) config('authn.app_host');

    $bapi = Route::middleware('bapi')->prefix('v1');
    if ($routingMode === 'subdomain') {
        $bapi->domain($bapiHost);
    } else {
        $bapi->prefix('api/v1');
    }
    $bapi->group(base_path('routes/bapi.php'));

    $dashboard = Route::middleware('dashboard');
    if ($routingMode === 'subdomain') {
        $dashboard->domain($dashboardHost);
    } else {
        $dashboard->prefix('dashboard');
    }
    $dashboard->group(base_path('routes/dashboard.php'));

    $fapi = Route::middleware('fapi');
    if ($routingMode === 'subdomain') {
        $fapi->domain('{env_slug}.'.$appHost);
    } else {
        $fapi->prefix('{env_slug}');
    }
    $fapi->group(base_path('routes/fapi.php'));
}

function bootstrapRoutingFixture(): array
{
    config(['app.url' => 'https://authn.local']);

    $service = app(BootstrapService::class);
    $result = $service->run([
        'admin_email' => 'op@example.com',
        'admin_password' => 'super-secret',
        'workspace_name' => 'Acme Inc',
        'app_url' => 'https://authn.local',
    ]);

    expect($result)->not->toBeNull();

    return $result;
}

function makeCustomerEnvironment(string $slug, string $kind = 'production'): Environment
{
    $project = Project::create([
        'owner_organization_id' => null,
        'name' => 'Customer '.$slug,
        'slug' => 'customer-'.$slug,
    ]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => $kind,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

/* ------------------------------------------------------------------ subdomain mode */

describe('subdomain mode', function (): void {
    beforeEach(function (): void {
        config([
            'authn.routing_mode' => 'subdomain',
            'authn.app_host' => 'authn.local',
            'authn.bapi_host' => 'api.authn.local',
            'authn.dashboard_host' => 'dashboard.authn.local',
        ]);
        reloadRoutes();
    });

    it('routes api.{app_host} → BAPI ping', function (): void {
        bootstrapRoutingFixture();

        // Bootstrap created an api key; resolve the secret and use it.
        $env = Environment::firstOrFail();
        $apiKey = ApiKey::where('environment_id', $env->id)
            ->where('kind', ApiKey::KIND_SECRET)
            ->firstOrFail();

        // The plaintext is not stored — we have to mint one for testing
        // by manufacturing a fresh sk_live_… token whose hash we plant
        // into the row, then issuing it as the bearer.
        $plaintext = 'sk_live_'.str_repeat('a', 32);
        $apiKey->forceFill(['hashed_secret' => hash('sha256', $plaintext)])->saveQuietly();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plaintext,
            'Host' => 'api.authn.local',
        ])->getJson('http://api.authn.local/v1/_ping');

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'surface' => 'bapi',
        ]);
    });

    it('routes dashboard.{app_host} → Dashboard ping', function (): void {
        $response = $this->withHeader('Host', 'dashboard.authn.local')
            ->getJson('http://dashboard.authn.local/_ping');

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'surface' => 'dashboard',
        ]);
    });

    it('routes {env_slug}.{app_host} → FAPI ping with the resolved env', function (): void {
        bootstrapRoutingFixture();
        makeCustomerEnvironment('acme');

        $response = $this->withHeader('Host', 'acme.authn.local')
            ->getJson('http://acme.authn.local/v1/_ping');

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'surface' => 'fapi',
            'environment_slug' => 'acme',
        ]);
    });

    it('returns 404 environment_not_found for an unknown env subdomain', function (): void {
        bootstrapRoutingFixture();

        $response = $this->withHeader('Host', 'ghost.authn.local')
            ->getJson('http://ghost.authn.local/v1/_ping');

        $response->assertNotFound()->assertJsonPath('errors.0.code', 'environment_not_found');
    });

    it('returns 404 for reserved env-slug subdomains', function (): void {
        $reserved = config('authn.reserved_env_slugs', []);
        expect($reserved)->toContain('docs');

        $response = $this->withHeader('Host', 'docs.authn.local')
            ->getJson('http://docs.authn.local/v1/_ping');

        $response->assertNotFound()->assertJsonPath('errors.0.code', 'environment_not_found');
    });

    it('routes the Account Portal at {env_slug}.{app_host}/account', function (): void {
        bootstrapRoutingFixture();
        makeCustomerEnvironment('beta');

        $response = $this->withHeader('Host', 'beta.authn.local')
            ->getJson('http://beta.authn.local/account/_ping');

        $response->assertOk()->assertJson([
            'environment_slug' => 'beta',
        ]);
    });
});

/* ------------------------------------------------------------------ path mode */

describe('path mode', function (): void {
    beforeEach(function (): void {
        config([
            'authn.routing_mode' => 'path',
            'authn.app_host' => 'authn.local',
            'authn.bapi_host' => 'authn.local',
            'authn.dashboard_host' => 'authn.local',
        ]);
        reloadRoutes();
    });

    it('routes /api/v1/_ping → BAPI', function (): void {
        bootstrapRoutingFixture();

        $env = Environment::firstOrFail();
        $apiKey = ApiKey::where('environment_id', $env->id)
            ->where('kind', ApiKey::KIND_SECRET)
            ->firstOrFail();
        $plaintext = 'sk_test_'.str_repeat('b', 32);
        $apiKey->forceFill(['hashed_secret' => hash('sha256', $plaintext)])->saveQuietly();

        $response = $this->withHeader('Authorization', 'Bearer '.$plaintext)
            ->getJson('http://authn.local/api/v1/_ping');

        $response->assertOk()->assertJson(['surface' => 'bapi']);
    });

    it('routes /dashboard/_ping → Dashboard', function (): void {
        $this->getJson('http://authn.local/dashboard/_ping')
            ->assertOk()
            ->assertJson(['surface' => 'dashboard']);
    });

    it('routes /{env_slug}/v1/_ping → FAPI', function (): void {
        bootstrapRoutingFixture();
        makeCustomerEnvironment('gamma');

        $this->getJson('http://authn.local/gamma/v1/_ping')
            ->assertOk()
            ->assertJson([
                'surface' => 'fapi',
                'environment_slug' => 'gamma',
            ]);
    });

    it('returns 404 for an unknown env-slug path segment', function (): void {
        $this->getJson('http://authn.local/nope/v1/_ping')
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'environment_not_found');
    });

    it('routes /{env_slug}/account/_ping → Account Portal', function (): void {
        bootstrapRoutingFixture();
        makeCustomerEnvironment('delta');

        $this->getJson('http://authn.local/delta/account/_ping')
            ->assertOk()
            ->assertJson(['environment_slug' => 'delta']);
    });
});
