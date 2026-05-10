<?php

declare(strict_types=1);

use App\Jobs\Sms\SendSmsTemplate;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\OauthProvider;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Role;
use App\Models\Session;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Keys\SigningKeyGenerator;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

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
        'configure/organizations' => 'Dashboard/Configure',
        'email-templates' => 'Dashboard/EmailTemplates',
        'api-keys' => 'Dashboard/ApiKeys',
        'webhooks' => 'Dashboard/Webhooks',
        'audit-log' => 'Dashboard/AuditLog',
        'organizations' => 'Dashboard/Organizations',
        'roles' => 'Dashboard/RolesAndPermissions',
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

it('renders the single-org dashboard view with members / invitations / requests / domains tabs', function (): void {
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
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme Org', 'slug' => 'acme-org']);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->get("http://dashboard.authn.local/acme/production/organizations/{$org->id}");
    $r->assertOk()
        ->assertJsonPath('component', 'Dashboard/Organization')
        ->assertJsonPath('props.organization.id', $org->id)
        ->assertJsonPath('props.tab', 'members');

    $r2 = $this->withHeaders(dashHeaders($bs['jwt']))
        ->get("http://dashboard.authn.local/acme/production/organizations/{$org->id}?tab=invitations");
    $r2->assertOk()->assertJsonPath('props.tab', 'invitations');
});

it('roles panel returns the seeded system roles + permissions', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->get('http://dashboard.authn.local/acme/production/roles');
    $r->assertOk()->assertJsonPath('component', 'Dashboard/RolesAndPermissions');
    $keys = array_column($r->json('props.roles'), 'key');
    expect($keys)->toContain('org:admin', 'org:member');
    expect(count($r->json('props.permissions')))->toBe(13);
});

it('redirects on unknown organization id back to organizations list', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->get('http://dashboard.authn.local/acme/production/organizations/org_nope');
    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('/acme/production/organizations');
});

it('Configure renders the multi-factor subsection with spec defaults when user_settings.multi_factor is unset', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->get('http://dashboard.authn.local/acme/production/configure/multi-factor');

    $r->assertOk()
        ->assertJsonPath('component', 'Dashboard/Configure')
        ->assertJsonPath('props.section', 'multi-factor')
        ->assertJsonPath('props.multi_factor.totp.enabled', true)
        ->assertJsonPath('props.multi_factor.backup_codes.enabled', true)
        ->assertJsonPath('props.multi_factor.backup_codes.default_count', 10);
});

it('PATCH /configure/multi-factor writes the toggles through to user_settings.multi_factor', function (): void {
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
        ->patch('http://dashboard.authn.local/acme/production/configure/multi-factor', [
            'totp' => ['enabled' => false],
            'backup_codes' => ['enabled' => true, 'default_count' => 16],
        ]);

    $r->assertRedirect();
    expect($env->fresh()->user_settings['multi_factor']['totp']['enabled'])->toBeFalse();
    expect($env->fresh()->user_settings['multi_factor']['backup_codes']['default_count'])->toBe(16);
    expect(session('multi_factor_saved'))->toBeTrue();
});

it('PATCH /configure/multi-factor rejects default_count outside 4..24 with validation errors', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->patch('http://dashboard.authn.local/acme/production/configure/multi-factor', [
            'totp' => ['enabled' => true],
            'backup_codes' => ['enabled' => true, 'default_count' => 3],
        ]);

    $r->assertStatus(422);
    $errors = $r->json('errors');
    expect($errors)->toHaveKey('backup_codes.default_count');
});

it('PATCH /configure/attributes writes phone_number tri-state through to user_settings.attributes', function (): void {
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
        ->patch('http://dashboard.authn.local/acme/production/configure/attributes', [
            'phone_number' => 'optional',
        ]);

    $r->assertRedirect();
    expect($env->fresh()->user_settings['attributes']['phone_number'])->toBe('optional');
});

it('PATCH /configure/attributes rejects unknown phone_number values', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->patch('http://dashboard.authn.local/acme/production/configure/attributes', [
            'phone_number' => 'maybe',
        ]);

    $r->assertStatus(422);
});

it('PATCH /configure/sms writes driver + from_number + credentials through with rotate semantics', function (): void {
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
        ->patch('http://dashboard.authn.local/acme/production/configure/sms', [
            'driver' => 'twilio',
            'from_number' => '+15555550100',
            'twilio' => ['account_sid' => 'AC123', 'auth_token' => 'tok-1'],
        ]);

    $r->assertRedirect();
    $sms = $env->fresh()->user_settings['sms'];
    expect($sms['driver'])->toBe('twilio');
    expect($sms['from_number'])->toBe('+15555550100');
    expect($sms['twilio']['account_sid'])->toBe('AC123');
    expect($sms['twilio']['auth_token'])->toBe('tok-1');

    // Empty auth_token leaves the stored value untouched.
    $r2 = $this->withHeaders(dashHeaders($bs['jwt']))
        ->patch('http://dashboard.authn.local/acme/production/configure/sms', [
            'driver' => 'twilio',
            'from_number' => '+15555550100',
            'twilio' => ['account_sid' => 'AC123', 'auth_token' => ''],
        ]);
    $r2->assertRedirect();
    expect($env->fresh()->user_settings['sms']['twilio']['auth_token'])->toBe('tok-1');
});

it('POST /configure/sms/test dispatches a SendSmsTemplate job', function (): void {
    Queue::fake();

    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->post('http://dashboard.authn.local/acme/production/configure/sms/test', [
            'to_number' => '+15555550100',
        ]);

    $r->assertRedirect();
    Queue::assertPushed(SendSmsTemplate::class);
});

it('POST /configure/sms/test 422s on non-E.164 input', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->post('http://dashboard.authn.local/acme/production/configure/sms/test', [
            'to_number' => 'bad',
        ]);

    $r->assertStatus(422);
});

it('PATCH /configure/sms-templates/{slug} updates the template body', function (): void {
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
        ->patch('http://dashboard.authn.local/acme/production/configure/sms-templates/verification_code', [
            'body' => 'Custom: {{otp_code}}',
        ]);

    $r->assertRedirect();
    $row = SmsTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('slug', 'verification_code')
        ->firstOrFail();
    expect($row->body)->toBe('Custom: {{otp_code}}');
});

it('Configure renders the sms section with seeded templates + null driver default', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->get('http://dashboard.authn.local/acme/production/configure/sms');

    $r->assertOk()
        ->assertJsonPath('component', 'Dashboard/Configure')
        ->assertJsonPath('props.section', 'sms')
        ->assertJsonPath('props.sms.driver', null)
        ->assertJsonCount(3, 'props.sms_templates');
});

it('Configure renders the social-providers section with the seeded preset rows + preset keys', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->get('http://dashboard.authn.local/acme/production/configure/social-providers');

    $r->assertOk()
        ->assertJsonPath('component', 'Dashboard/Configure')
        ->assertJsonPath('props.section', 'social-providers');
    $keys = collect($r->json('props.oauth_providers'))->pluck('provider_key')->sort()->values()->all();
    expect($keys)->toBe(['apple', 'github', 'google', 'microsoft']);
    expect($r->json('props.oauth_preset_keys'))->toBe(['google', 'github', 'apple', 'microsoft']);
});

it('PATCH /configure/oauth-providers/{id} flips enabled + rotates client_secret', function (): void {
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
    $row = OauthProvider::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)->where('provider_key', 'google')->firstOrFail();

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->patch('http://dashboard.authn.local/acme/production/configure/oauth-providers/'.$row->id, [
            'enabled' => true,
            'client_id' => 'gid-real',
            'client_secret' => 'rotated',
        ]);

    $r->assertRedirect();
    $row->refresh();
    expect($row->enabled)->toBeTrue();
    expect($row->client_id)->toBe('gid-real');
    expect($row->encrypted_client_secret)->toBe('rotated');
});

it('PATCH /configure/oauth-providers/{id} leaves client_secret untouched on empty input', function (): void {
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
    $row = OauthProvider::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)->where('provider_key', 'google')->firstOrFail();
    $row->forceFill(['encrypted_client_secret' => 'kept'])->save();

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->patch('http://dashboard.authn.local/acme/production/configure/oauth-providers/'.$row->id, [
            'enabled' => true,
            'client_secret' => '',
        ]);

    $r->assertRedirect();
    expect($row->fresh()->encrypted_client_secret)->toBe('kept');
});

it('POST /configure/oauth-providers creates a custom OAuth2 row', function (): void {
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
        ->post('http://dashboard.authn.local/acme/production/configure/oauth-providers', [
            'provider_kind' => 'custom_oauth2',
            'provider_key' => 'acmecorp',
            'name' => 'Acme Corp',
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'authorization_endpoint' => 'https://acme.test/authorize',
            'token_endpoint' => 'https://acme.test/token',
            'userinfo_endpoint' => 'https://acme.test/userinfo',
            'userinfo_method' => 'GET',
            'userinfo_auth' => 'bearer',
        ]);

    $r->assertRedirect();
    $row = OauthProvider::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)->where('provider_key', 'acmecorp')->first();
    expect($row)->not->toBeNull();
    expect($row->authorization_endpoint)->toBe('https://acme.test/authorize');
});

it('POST /configure/oauth-providers runs OIDC discovery for custom_oidc', function (): void {
    Http::fake([
        'https://idp.acme.test/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.acme.test/authorize',
            'token_endpoint' => 'https://idp.acme.test/token',
            'userinfo_endpoint' => 'https://idp.acme.test/userinfo',
            'jwks_uri' => 'https://idp.acme.test/jwks',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ], 200),
    ]);

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
        ->post('http://dashboard.authn.local/acme/production/configure/oauth-providers', [
            'provider_kind' => 'custom_oidc',
            'provider_key' => 'acmeoidc',
            'name' => 'Acme OIDC',
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'issuer' => 'https://idp.acme.test',
        ]);

    $r->assertRedirect();
    $row = OauthProvider::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)->where('provider_key', 'acmeoidc')->first();
    expect($row)->not->toBeNull();
    expect($row->authorization_endpoint)->toBe('https://idp.acme.test/authorize');
});

it('DELETE /configure/oauth-providers/{id} removes the row when no ExternalAccounts link', function (): void {
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
    $row = OauthProvider::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)->where('provider_key', 'google')->firstOrFail();

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->delete('http://dashboard.authn.local/acme/production/configure/oauth-providers/'.$row->id);

    $r->assertRedirect();
    expect(OauthProvider::query()->withoutGlobalScopes()->where('id', $row->id)->whereNull('deleted_at')->exists())->toBeFalse();
});
