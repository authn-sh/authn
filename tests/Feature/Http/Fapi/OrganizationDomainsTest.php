<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

function fapiDomainReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

function fapiDomainMakeOrg(Environment $env, User $user, string $roleKey = 'org:admin'): Organization
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
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Outside', 'slug' => 'outside']);

    fapiDomainReq('GET', "/organizations/{$org->id}/domains", $auth['jwt'])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'organization_not_found');
});

it('lets members read but rejects POST with 403 (read-only role)', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiDomainMakeOrg($f['env'], $auth['user'], 'org:member');

    fapiDomainReq('GET', "/organizations/{$org->id}/domains", $auth['jwt'])->assertOk();

    fapiDomainReq('POST', "/organizations/{$org->id}/domains", $auth['jwt'], ['name' => 'denied.test'])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'authorization_invalid');
});

it('lists domains for the path organization only', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiDomainMakeOrg($f['env'], $auth['user']);
    $other = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Other', 'slug' => 'other']);

    OrganizationDomain::create(['environment_id' => $f['env']->id, 'organization_id' => $org->id, 'name' => 'acme.test', 'verified' => true]);
    OrganizationDomain::create(['environment_id' => $f['env']->id, 'organization_id' => $org->id, 'name' => 'acme2.test', 'verified' => false]);
    OrganizationDomain::create(['environment_id' => $f['env']->id, 'organization_id' => $other->id, 'name' => 'other.test', 'verified' => true]);

    $r = fapiDomainReq('GET', "/organizations/{$org->id}/domains", $auth['jwt']);
    $r->assertOk()
        ->assertJsonPath('total_count', 2);
});

it('filters by verified=true via the query string', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiDomainMakeOrg($f['env'], $auth['user']);

    OrganizationDomain::create(['environment_id' => $f['env']->id, 'organization_id' => $org->id, 'name' => 'verified.test', 'verified' => true]);
    OrganizationDomain::create(['environment_id' => $f['env']->id, 'organization_id' => $org->id, 'name' => 'unverified.test', 'verified' => false]);

    $r = fapiDomainReq('GET', "/organizations/{$org->id}/domains?verified=true", $auth['jwt']);
    $r->assertOk()
        ->assertJsonPath('total_count', 1)
        ->assertJsonPath('data.0.name', 'verified.test');
});

it('creates a domain via POST with enrollment_mode', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiDomainMakeOrg($f['env'], $auth['user']);

    $r = fapiDomainReq('POST', "/organizations/{$org->id}/domains", $auth['jwt'], [
        'name' => 'ACME.com',
        'enrollment_mode' => 'automatic_invitation',
    ]);
    $r->assertOk()
        ->assertJsonPath('response.object', 'organization_domain')
        ->assertJsonPath('response.name', 'acme.com')
        ->assertJsonPath('response.verified', false)
        ->assertJsonPath('response.enrollment_mode', 'automatic_invitation')
        ->assertJsonPath('client.object', 'client');
});

it('returns 409 when the domain already exists in the environment', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiDomainMakeOrg($f['env'], $auth['user']);
    OrganizationDomain::create(['environment_id' => $f['env']->id, 'organization_id' => $org->id, 'name' => 'acme.com', 'verified' => false]);

    fapiDomainReq('POST', "/organizations/{$org->id}/domains", $auth['jwt'], ['name' => 'acme.com'])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'form_identifier_exists');
});

it('returns 404 when the domain belongs to another organization', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiDomainMakeOrg($f['env'], $auth['user']);
    $other = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Other', 'slug' => 'other-fapi-domain']);
    $foreign = OrganizationDomain::create(['environment_id' => $f['env']->id, 'organization_id' => $other->id, 'name' => 'foreign.test', 'verified' => false]);

    fapiDomainReq('GET', "/organizations/{$org->id}/domains/{$foreign->id}", $auth['jwt'])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'organization_domain_not_found');
});

it('updates enrollment_mode via PATCH', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiDomainMakeOrg($f['env'], $auth['user']);
    $domain = OrganizationDomain::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'name' => 'flip.test',
        'verified' => true,
        'enrollment_mode' => OrganizationDomain::MODE_MANUAL_INVITATION,
    ]);

    $r = fapiDomainReq('PATCH', "/organizations/{$org->id}/domains/{$domain->id}", $auth['jwt'], [
        'enrollment_mode' => 'automatic_suggestion',
    ]);
    $r->assertOk()
        ->assertJsonPath('response.enrollment_mode', 'automatic_suggestion');

    expect(OrganizationDomain::query()->find($domain->id)->enrollment_mode)->toBe('automatic_suggestion');
});

it('DELETE removes the domain and returns the snapshot enveloped', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiDomainMakeOrg($f['env'], $auth['user']);
    $domain = OrganizationDomain::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'name' => 'goodbye.test',
        'verified' => false,
    ]);

    $r = fapiDomainReq('DELETE', "/organizations/{$org->id}/domains/{$domain->id}", $auth['jwt']);
    $r->assertOk()
        ->assertJsonPath('response.object', 'organization_domain')
        ->assertJsonPath('response.id', $domain->id);

    expect(OrganizationDomain::query()->find($domain->id))->toBeNull();
});

it('rejects PATCH from members lacking org:sys_domains:manage', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    // Pre-seed a domain via an admin so we can test the read-only role.
    $admin = User::create(['environment_id' => $f['env']->id, 'first_name' => 'Admin']);
    $org = fapiDomainMakeOrg($f['env'], $admin, 'org:admin');

    // Add the auth'd user as a member-only role.
    $memberRole = Role::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('key', 'org:member')
        ->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'user_id' => $auth['user']->id,
        'role_id' => $memberRole->id,
    ]);

    $domain = OrganizationDomain::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'name' => 'readonly.test',
        'verified' => false,
    ]);

    fapiDomainReq('PATCH', "/organizations/{$org->id}/domains/{$domain->id}", $auth['jwt'], [
        'enrollment_mode' => 'automatic_invitation',
    ])->assertStatus(403);
});
