<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\Project;
use App\Models\User;
use App\Services\Client\ClientResolver;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

function bootEnvForDomainRouting(): array
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

    $project = Project::create(['name' => 'P', 'slug' => 'p-'.uniqid()]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => ['https://app.example.com'],
    ]);
    (new SigningKeyGenerator)->generate($env);

    $client = Client::create(['environment_id' => $env->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($client);

    return ['env' => $env, 'origin' => 'https://app.example.com', 'cookie' => $cookie];
}

it('narrows supported_strategies to [enterprise_sso] when the identifier domain matches an instance-wide connection', function (): void {
    $f = bootEnvForDomainRouting();
    $user = User::create(['environment_id' => $f['env']->id]);
    $user->setPassword('super-secret-password');
    $user->save();
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'email_address' => 'alice@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);
    EnterpriseConnection::factory()->create([
        'environment_id' => $f['env']->id,
        'organization_id' => null,
        'domains' => ['acme.test'],
    ]);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $f['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@acme.test',
        ]);

    $r->assertOk()
        ->assertJsonPath('response.status', 'needs_first_factor')
        ->assertJsonPath('response.supported_strategies', ['enterprise_sso']);
    expect($r->json('response.enterprise_connection_id'))->not->toBeNull();
});

it('prefers the org-scoped connection over an instance-wide one when both cover the domain', function (): void {
    $f = bootEnvForDomainRouting();
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    OrganizationDomain::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'name' => 'acme.test',
        'verified' => true,
        'enrollment_mode' => OrganizationDomain::MODE_MANUAL_INVITATION,
    ]);

    $user = User::create(['environment_id' => $f['env']->id]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'email_address' => 'alice@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);
    EnterpriseConnection::factory()->create([
        'environment_id' => $f['env']->id,
        'organization_id' => null,
        'domains' => ['acme.test'],
    ]);
    $orgConn = EnterpriseConnection::factory()->create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'domains' => ['acme.test'],
    ]);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $f['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@acme.test',
        ]);

    $r->assertOk()
        ->assertJsonPath('response.enterprise_connection_id', $orgConn->id)
        ->assertJsonPath('response.supported_strategies', ['enterprise_sso']);
});

it('keeps the full first-factor strategy list when no enterprise connection covers the identifier domain', function (): void {
    $f = bootEnvForDomainRouting();
    $user = User::create(['environment_id' => $f['env']->id]);
    $user->setPassword('super-secret-password');
    $user->save();
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'email_address' => 'bob@other.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $f['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'bob@other.test',
        ]);

    $r->assertOk()
        ->assertJsonPath('response.enterprise_connection_id', null);
    $strategies = $r->json('response.supported_strategies');
    expect($strategies)->toContain('password');
    expect($strategies)->toContain('email_code');
    expect($strategies)->not->toContain('enterprise_sso');
});

it('skips a disabled enterprise connection when narrowing strategies', function (): void {
    $f = bootEnvForDomainRouting();
    $user = User::create(['environment_id' => $f['env']->id]);
    $user->setPassword('super-secret-password');
    $user->save();
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'email_address' => 'alice@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);
    EnterpriseConnection::factory()->disabled()->create([
        'environment_id' => $f['env']->id,
        'organization_id' => null,
        'domains' => ['acme.test'],
    ]);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $f['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@acme.test',
        ]);

    $r->assertOk()
        ->assertJsonPath('response.enterprise_connection_id', null);
    expect($r->json('response.supported_strategies'))->not->toContain('enterprise_sso');
});
