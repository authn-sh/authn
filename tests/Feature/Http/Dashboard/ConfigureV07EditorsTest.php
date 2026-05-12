<?php

declare(strict_types=1);

use App\Models\AuthorizationGrant;
use App\Models\Environment;
use App\Models\JwtTemplate;
use App\Models\OauthApplication;
use App\Models\Project;
use App\Models\User;
use Tests\Feature\Http\Dashboard\DashboardTestSupport;

function bootEditorsEnv(): array
{
    $f = DashboardTestSupport::bootAdminEnv();
    $bs = DashboardTestSupport::operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    return ['env' => $env, 'project' => $project, 'jwt' => $bs['jwt'], 'workspace' => $bs['workspace']];
}

it('renders JWT Templates + OAuth Applications subsections on Configure', function (): void {
    $f = bootEditorsEnv();
    JwtTemplate::factory()->create(['environment_id' => $f['env']->id, 'name' => 'supabase']);
    OauthApplication::factory()->create(['environment_id' => $f['env']->id, 'name' => 'Acme Dashboard']);

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->get('http://dashboard.authn.local/acme/production/configure/jwt-templates');
    $r->assertOk()->assertJsonPath('component', 'Dashboard/Configure');
    expect($r->json('props.jwt_templates.0.name'))->toBe('supabase');
    expect($r->json('props.oauth_applications.0.name'))->toBe('Acme Dashboard');
});

it('stores a JWT template via the dashboard endpoint', function (): void {
    $f = bootEditorsEnv();

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->postJson('http://dashboard.authn.local/acme/production/configure/jwt-templates', [
            'name' => 'supabase',
            'claims' => ['sub' => '{{user.id}}', 'role' => 'authenticated'],
            'lifetime' => 300,
        ]);
    $r->assertRedirect();

    $row = JwtTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('name', 'supabase')
        ->first();
    expect($row)->not->toBeNull();
    expect($row->lifetime)->toBe(300);
    expect($row->claims['role'])->toBe('authenticated');
});

it('rejects JWT template names that violate the slug pattern', function (): void {
    $f = bootEditorsEnv();

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->postJson('http://dashboard.authn.local/acme/production/configure/jwt-templates', [
            'name' => 'BadName',
            'claims' => ['sub' => '{{user.id}}'],
        ]);
    $r->assertStatus(422);
});

it('patches a JWT template (claims + lifetime + clock skew + custom_signing_key revert)', function (): void {
    $f = bootEditorsEnv();
    $row = JwtTemplate::factory()->withCustomSigningKey('--initial--')->create([
        'environment_id' => $f['env']->id,
        'name' => 'tmpl',
        'lifetime' => 60,
    ]);

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->patchJson('http://dashboard.authn.local/acme/production/configure/jwt-templates/'.$row->id, [
            'claims' => ['sub' => '{{user.external_id}}'],
            'lifetime' => 900,
            'custom_signing_key' => null,
        ]);
    $r->assertRedirect();

    $row->refresh();
    expect($row->lifetime)->toBe(900);
    expect($row->claims['sub'])->toBe('{{user.external_id}}');
    expect($row->custom_signing_key)->toBeNull();
});

it('soft-deletes a JWT template via the dashboard endpoint', function (): void {
    $f = bootEditorsEnv();
    $row = JwtTemplate::factory()->create(['environment_id' => $f['env']->id, 'name' => 'temp']);

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->deleteJson('http://dashboard.authn.local/acme/production/configure/jwt-templates/'.$row->id);
    $r->assertRedirect();

    $alive = JwtTemplate::query()->withoutGlobalScopes()
        ->where('id', $row->id)
        ->whereNull('removed_at')
        ->exists();
    expect($alive)->toBeFalse();
});

it('creates a confidential OAuth application and flashes the plaintext secret once', function (): void {
    $f = bootEditorsEnv();

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->postJson('http://dashboard.authn.local/acme/production/configure/oauth-applications', [
            'name' => 'Acme Dashboard',
            'callback_urls' => ['https://app.acme.example/oauth/callback'],
            'scopes' => ['openid', 'profile', 'email'],
        ]);
    $r->assertRedirect()->assertSessionHas('oauth_application_secret');
    $secret = session('oauth_application_secret');
    expect($secret)->toStartWith('osec_');

    $row = OauthApplication::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('name', 'Acme Dashboard')
        ->first();
    expect($row)->not->toBeNull();
    expect($row->verifyClientSecret($secret))->toBeTrue();
});

it('creates a public OAuth application without a client_secret', function (): void {
    $f = bootEditorsEnv();

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->postJson('http://dashboard.authn.local/acme/production/configure/oauth-applications', [
            'name' => 'Acme Mobile',
            'callback_urls' => ['com.acme.app://oauth/callback'],
            'is_public' => true,
        ]);
    $r->assertRedirect();

    $row = OauthApplication::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('name', 'Acme Mobile')
        ->first();
    expect($row->is_public)->toBeTrue();
    expect($row->hashed_client_secret)->toBeNull();
});

it('rotates a confidential client secret and flashes the new plaintext once', function (): void {
    $f = bootEditorsEnv();
    $row = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->postJson('http://dashboard.authn.local/acme/production/configure/oauth-applications/'.$row->id.'/rotate-secret');
    $r->assertRedirect()->assertSessionHas('oauth_application_secret');
    $newSecret = session('oauth_application_secret');
    expect($newSecret)->toStartWith('osec_');
    expect($row->fresh()->verifyClientSecret($newSecret))->toBeTrue();
});

it('refuses to rotate a public client secret (no-op)', function (): void {
    $f = bootEditorsEnv();
    $row = OauthApplication::factory()->public()->create(['environment_id' => $f['env']->id]);

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->postJson('http://dashboard.authn.local/acme/production/configure/oauth-applications/'.$row->id.'/rotate-secret');
    $r->assertRedirect();
    expect($row->fresh()->hashed_client_secret)->toBeNull();
});

it('soft-deletes an OAuth application and cascade-revokes its AuthorizationGrants', function (): void {
    $f = bootEditorsEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    $row = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    $grant = AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $row->id,
        'user_id' => $user->id,
    ]);
    expect($grant->fresh()->isActive())->toBeTrue();

    $r = $this->withHeaders(DashboardTestSupport::headers($f['jwt']))
        ->deleteJson('http://dashboard.authn.local/acme/production/configure/oauth-applications/'.$row->id);
    $r->assertRedirect();

    expect($grant->fresh()->isActive())->toBeFalse();
    $alive = OauthApplication::query()->withoutGlobalScopes()
        ->where('id', $row->id)->whereNull('removed_at')->exists();
    expect($alive)->toBeFalse();
});
