<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\EmailAddress;
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

function reloadOrgPagesRoutes(): void
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

function bootOrgPagesEnv(): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
    ]);
    reloadOrgPagesRoutes();

    $project = Project::create(['name' => 'P', 'slug' => 'p-org-pages']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => ['https://app.example.com'],
        'appearance' => ['application_name' => 'Acme'],
        'home_url' => 'https://app.example.com',
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['env' => $env];
}

function makeOrgPagesSession(Environment $env): array
{
    $client = Client::create(['environment_id' => $env->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($client);
    $user = new User(['environment_id' => $env->id]);
    $user->save();
    EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'op@example.com',
        'verified_at' => now(),
        'is_primary' => true,
    ]);
    Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
    ]);

    return ['client' => $client, 'cookie' => $cookie, 'user' => $user];
}

it('GET /organization-list returns the Inertia page when authenticated', function (): void {
    $f = bootOrgPagesEnv();
    $auth = makeOrgPagesSession($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $auth['cookie'])
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '1',
            'Accept' => 'application/json',
        ])->getJson('https://acme.authn.local/organization-list');

    $r->assertOk()->assertJsonPath('component', 'AccountPortal/OrganizationList');
});

it('GET /organization-list redirects anon to sign-in', function (): void {
    bootOrgPagesEnv();

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->get('https://acme.authn.local/organization-list');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('/sign-in');
});

it('GET /create-organization returns the Inertia page when authenticated', function (): void {
    $f = bootOrgPagesEnv();
    $auth = makeOrgPagesSession($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $auth['cookie'])
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '1',
            'Accept' => 'application/json',
        ])->getJson('https://acme.authn.local/create-organization');

    $r->assertOk()->assertJsonPath('component', 'AccountPortal/CreateOrganization');
});

it('GET /create-organization redirects anon to sign-in', function (): void {
    bootOrgPagesEnv();

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->get('https://acme.authn.local/create-organization');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('/sign-in');
});

it('GET /organization renders the page; passes organizationId/tab to Inertia', function (): void {
    $f = bootOrgPagesEnv();
    $auth = makeOrgPagesSession($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $auth['cookie'])
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '1',
            'Accept' => 'application/json',
        ])->getJson('https://acme.authn.local/organization/org_01HABCDEF1234567890123/members');

    $r->assertOk()
        ->assertJsonPath('component', 'AccountPortal/OrganizationProfile')
        ->assertJsonPath('props.organizationId', 'org_01HABCDEF1234567890123')
        ->assertJsonPath('props.tab', 'members');
});

it('GET /organization without an id renders the page with null id (defaults to active org)', function (): void {
    $f = bootOrgPagesEnv();
    $auth = makeOrgPagesSession($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $auth['cookie'])
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '1',
            'Accept' => 'application/json',
        ])->getJson('https://acme.authn.local/organization');

    $r->assertOk()
        ->assertJsonPath('component', 'AccountPortal/OrganizationProfile')
        ->assertJsonPath('props.organizationId', null);
});

it('GET /organization/{id}/{tab} rejects an unknown tab', function (): void {
    $f = bootOrgPagesEnv();
    $auth = makeOrgPagesSession($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $auth['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->get('https://acme.authn.local/organization/org_x/billing');

    // The `where` regex on the route constrains tab to the documented set;
    // unknown tabs fall through to a 4xx (Laravel's 405 / 404 depending on
    // route order). The point of the test is that the page does NOT render.
    expect($r->status())->toBeGreaterThanOrEqual(400);
    expect($r->status())->toBeLessThan(500);
});

it('GET /organization redirects anon to sign-in', function (): void {
    bootOrgPagesEnv();

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->get('https://acme.authn.local/organization');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('/sign-in');
});
