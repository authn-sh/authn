<?php

declare(strict_types=1);

use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

function fapiOrgEcReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

function fapiEcMakeOrg(Environment $env, User $user, string $roleKey = 'org:admin'): Organization
{
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $role = Role::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('key', $roleKey)
        ->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $org;
}

it('rejects non-members with 404 organization_not_found', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    // Org exists but the user has no membership.
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Outside', 'slug' => 'outside']);

    $r = fapiOrgEcReq('GET', "/organizations/{$org->id}/enterprise-connections", $auth['jwt']);
    $r->assertStatus(404)->assertJsonPath('errors.0.code', 'organization_not_found');
});

it('rejects members lacking org:sso:manage with 403', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiEcMakeOrg($f['env'], $auth['user'], 'org:member'); // member role does NOT have sso:manage

    $r = fapiOrgEcReq('GET', "/organizations/{$org->id}/enterprise-connections", $auth['jwt']);
    $r->assertStatus(403)->assertJsonPath('errors.0.code', 'authorization_invalid');
});

it('allows org:admin (which has sso:manage) to list connections', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiEcMakeOrg($f['env'], $auth['user']);
    EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id, 'organization_id' => $org->id]);
    EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id, 'organization_id' => null]); // not scoped to this org

    $r = fapiOrgEcReq('GET', "/organizations/{$org->id}/enterprise-connections", $auth['jwt']);

    $r->assertOk()->assertJsonPath('total_count', 1);
});

it('creates a per-org SAML connection scoped to the path organization_id', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiEcMakeOrg($f['env'], $auth['user']);

    $r = fapiOrgEcReq('POST', "/organizations/{$org->id}/enterprise-connections", $auth['jwt'], [
        'protocol' => 'saml',
        'name' => 'Acme SSO',
        'domains' => ['acme.test'],
        'default_role' => 'org:member',
        'saml_idp_entity_id' => 'https://idp.example.com/saml/metadata',
        'saml_sso_url' => 'https://idp.example.com/saml/sso',
        'saml_idp_certificate' => "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----",
        'organization_id' => 'will-be-ignored',
    ]);

    $r->assertCreated()
        ->assertJsonPath('protocol', 'saml')
        ->assertJsonPath('organization_id', $org->id);
});

it('refuses to delete a connection with linked accounts (409)', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiEcMakeOrg($f['env'], $auth['user']);
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id, 'organization_id' => $org->id]);
    $other = User::create(['environment_id' => $f['env']->id]);
    EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $other->id,
        'enterprise_connection_id' => $conn->id,
    ]);

    $r = fapiOrgEcReq('DELETE', "/organizations/{$org->id}/enterprise-connections/{$conn->id}", $auth['jwt']);
    $r->assertStatus(409)->assertJsonPath('errors.0.code', 'enterprise_connection_in_use');
});

it('returns 404 when the connection belongs to a different org', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiEcMakeOrg($f['env'], $auth['user']);

    // Connection scoped to a different org
    $otherOrg = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Other', 'slug' => 'other']);
    $conn = EnterpriseConnection::factory()->create([
        'environment_id' => $f['env']->id,
        'organization_id' => $otherOrg->id,
    ]);

    $r = fapiOrgEcReq('GET', "/organizations/{$org->id}/enterprise-connections/{$conn->id}", $auth['jwt']);
    $r->assertStatus(404)->assertJsonPath('errors.0.code', 'enterprise_connection_not_found');
});

it('patches a connection name + enabled', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiEcMakeOrg($f['env'], $auth['user']);
    $conn = EnterpriseConnection::factory()->create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'name' => 'Old',
    ]);

    $r = fapiOrgEcReq('PATCH', "/organizations/{$org->id}/enterprise-connections/{$conn->id}", $auth['jwt'], [
        'name' => 'New',
        'enabled' => false,
    ]);
    $r->assertOk()->assertJsonPath('name', 'New')->assertJsonPath('enabled', false);
});
