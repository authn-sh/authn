<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Role;
use App\Models\Session;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Keys\SigningKeyGenerator;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function reloadDashboardRoutes(): void
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

function bootAdminEnv(): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
        'authn.app_scheme' => 'http',
        'authn.app_port_suffix' => '',
        'app.url' => 'http://authn.local',
    ]);
    reloadDashboardRoutes();

    $project = Project::create(['name' => 'authn.sh admin', 'slug' => Project::SYSTEM_SLUG, 'is_system' => true]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => Project::SYSTEM_SLUG,
        'routing_label' => '_admin',
        'allowed_origins' => [],
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['project' => $project, 'env' => $env];
}

function operatorWithMembership(Environment $env): array
{
    $org = Organization::create([
        'environment_id' => $env->id,
        'name' => 'Acme Workspace',
        'slug' => 'acme-workspace',
    ]);
    $user = new User(['environment_id' => $env->id, 'first_name' => 'Op']);
    $user->save();
    EmailAddress::create([
        'environment_id' => $env->id, 'user_id' => $user->id,
        'email_address' => 'op@example.com', 'verified_at' => now(), 'is_primary' => true,
    ]);
    $adminRole = Role::withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('key', 'org:admin')
        ->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $adminRole->id,
    ]);
    $client = Client::create(['environment_id' => $env->id]);
    $session = Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
    ]);
    $jwt = app(SessionTokenIssuer::class)->mint($session->fresh());

    return ['user' => $user, 'workspace' => $org, 'session' => $session, 'jwt' => $jwt['jwt']];
}

function dashHeaders(?string $jwt = null): array
{
    return array_filter([
        'Host' => 'dashboard.authn.local',
        'Accept' => 'application/json',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
        'Authorization' => $jwt !== null ? 'Bearer '.$jwt : null,
    ]);
}

it('redirects unauthenticated requests to the _admin sign-in URL', function (): void {
    bootAdminEnv();
    $r = $this->withHeaders(['Host' => 'dashboard.authn.local'])
        ->get('http://dashboard.authn.local/');
    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('authn.local/sign-in');
});

it('routes operator without a workspace membership to CreateWorkspace', function (): void {
    $f = bootAdminEnv();
    // Operator with no OrganizationMembership row.
    $user = new User(['environment_id' => $f['env']->id, 'first_name' => 'Op']);
    $user->save();
    EmailAddress::create([
        'environment_id' => $f['env']->id, 'user_id' => $user->id,
        'email_address' => 'no-org@example.com', 'verified_at' => now(), 'is_primary' => true,
    ]);
    $client = Client::create(['environment_id' => $f['env']->id]);
    $session = Session::create([
        'environment_id' => $f['env']->id, 'client_id' => $client->id, 'user_id' => $user->id, 'status' => Session::STATUS_ACTIVE,
    ]);
    $jwt = app(SessionTokenIssuer::class)->mint($session->fresh())['jwt'];

    $r = $this->withHeaders(dashHeaders($jwt))
        ->get('http://dashboard.authn.local/');
    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('/create-workspace');
});

it('routes operator with workspace but no projects to CreateProject', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->get('http://dashboard.authn.local/');
    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('/create-project');
});

it('overview / users / sessions / api keys / webhooks pages render for authed operators', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    // Plant a non-admin project + production env.
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);
    ApiKey::query()->create([
        'environment_id' => $env->id,
        'kind' => ApiKey::KIND_SECRET,
        'prefix' => 'sk_live_test1234',
        'hashed_secret' => hash('sha256', 'sk_live_'.str_repeat('z', 32)),
        'name' => 'Test',
    ]);

    foreach ([
        'overview' => 'Dashboard/Overview',
        'users' => 'Dashboard/Users',
        'sessions' => 'Dashboard/Sessions',
        'invitations' => 'Dashboard/Invitations',
        'allowlist' => 'Dashboard/Allowlist',
        'blocklist' => 'Dashboard/Blocklist',
        'configure/attributes' => 'Dashboard/Configure',
        'email-templates' => 'Dashboard/EmailTemplates',
        'api-keys' => 'Dashboard/ApiKeys',
        'webhooks' => 'Dashboard/Webhooks',
        'audit-log' => 'Dashboard/AuditLog',
    ] as $segment => $component) {
        $r = $this->withHeaders(dashHeaders($bs['jwt']))
            ->get("http://dashboard.authn.local/acme/production/{$segment}");
        $r->assertOk()
            ->assertJsonPath('component', $component);
    }

    // Home redirects into the project's overview now that one exists.
    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->get('http://dashboard.authn.local/');
    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('/acme/production/overview');
});

it('rotates an API key and stashes the secret in the flash bag', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);
    $apiKey = ApiKey::query()->create([
        'environment_id' => $env->id,
        'kind' => ApiKey::KIND_SECRET,
        'prefix' => 'sk_live_xyz0000',
        'hashed_secret' => hash('sha256', 'sk_live_old'),
        'name' => 'Default',
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->post("http://dashboard.authn.local/acme/production/api-keys/{$apiKey->id}/rotate");
    $r->assertRedirect();
    expect(session('rotated_secret'))->toStartWith('sk_live_');
    expect($apiKey->fresh()->hashed_secret)->not->toBe(hash('sha256', 'sk_live_old'));
});

it('creates a webhook endpoint with the secret returned in the flash bag', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->post('http://dashboard.authn.local/acme/production/webhooks', [
            'url' => 'https://customer.example.com/hook',
        ]);
    $r->assertRedirect();
    expect(session('signing_secret'))->toStartWith('whsec_');
    expect(WebhookEndpoint::query()->withoutGlobalScopes()->where('environment_id', $env->id)->count())->toBe(1);
});
