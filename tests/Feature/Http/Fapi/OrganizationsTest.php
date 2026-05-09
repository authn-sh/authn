<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationCreated;
use App\Events\Organizations\OrganizationDeleted;
use App\Events\Organizations\OrganizationMembershipDeleted;
use App\Events\Organizations\OrganizationMembershipUpdated;
use App\Events\Organizations\OrganizationUpdated;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;


function fapiOrgReq(string $method, string $path, string $jwt, array $body = [], string $origin = 'https://app.example.com'): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => $origin,
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

function fapiAddMembership(Environment $env, string $orgId, User $user, string $roleKey): OrganizationMembership
{
    $role = Role::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('key', $roleKey)
        ->firstOrFail();

    $row = OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $orgId,
        'user_id' => $user->id,
        'role_id' => $role->id,
    ]);
    Organization::query()->withoutGlobalScopes()->where('id', $orgId)->increment('members_count');

    return $row;
}

it('POST /v1/organizations creates an org + admin membership and wraps the response in {client, response}', function (): void {
    Event::fake([OrganizationCreated::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = fapiOrgReq('POST', '/organizations', $auth['jwt'], [
        'name' => 'Acme Inc',
        'slug' => 'acme-fapi',
    ]);

    $r->assertStatus(201)
        ->assertJsonPath('response.object', 'organization')
        ->assertJsonPath('response.name', 'Acme Inc')
        ->assertJsonPath('response.slug', 'acme-fapi')
        ->assertJsonPath('client.object', 'client');
    expect($r->json('response.id'))->toStartWith('org_');

    // Creator is auto-bound to the org:admin role.
    $orgId = $r->json('response.id');
    $membership = OrganizationMembership::query()->where('organization_id', $orgId)->firstOrFail();
    expect($membership->user_id)->toBe($auth['user']->id);
    expect($membership->role->key)->toBe('org:admin');

    Event::assertDispatched(OrganizationCreated::class, 1);
});

it('POST /v1/organizations 403s when allow_user_created_organizations is false', function (): void {
    $f = MeTestSupport::bootEnv(userSettings: [
        'organization_settings' => ['allow_user_created_organizations' => false],
    ]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    fapiOrgReq('POST', '/organizations', $auth['jwt'], ['name' => 'Acme'])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'organization_creation_disabled');
});

it('POST /v1/organizations 422s when max_organizations_per_user is reached', function (): void {
    $f = MeTestSupport::bootEnv(userSettings: [
        'organization_settings' => ['max_organizations_per_user' => 1],
    ]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    fapiOrgReq('POST', '/organizations', $auth['jwt'], ['name' => 'First'])->assertStatus(201);
    fapiOrgReq('POST', '/organizations', $auth['jwt'], ['name' => 'Second'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'organization_quota_exceeded');
});

it('GET /v1/organizations/{id} returns 404 to non-members (privacy default)', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    // Create an org that the auth user is NOT a member of.
    $other = new User(['environment_id' => $f['env']->id, 'username' => 'other']);
    $other->save();
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Private', 'slug' => 'private']);
    fapiAddMembership($f['env'], $org->id, $other, 'org:admin');

    fapiOrgReq('GET', "/organizations/{$org->id}", $auth['jwt'])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'organization_not_found');
});

it('GET /v1/organizations/{id} returns the org for a member', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    fapiAddMembership($f['env'], $org->id, $auth['user'], 'org:member');

    fapiOrgReq('GET', "/organizations/{$org->id}", $auth['jwt'])
        ->assertOk()
        ->assertJsonPath('object', 'organization')
        ->assertJsonPath('id', $org->id);
});

it('PATCH /v1/organizations/{id} 403s without org:sys_profile:manage (member only)', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    fapiAddMembership($f['env'], $org->id, $auth['user'], 'org:member');

    fapiOrgReq('PATCH', "/organizations/{$org->id}", $auth['jwt'], ['name' => 'Hacked'])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'authorization_invalid');
});

it('PATCH /v1/organizations/{id} succeeds for an admin and fires OrganizationUpdated', function (): void {
    Event::fake([OrganizationUpdated::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    fapiAddMembership($f['env'], $org->id, $auth['user'], 'org:admin');

    $r = fapiOrgReq('PATCH', "/organizations/{$org->id}", $auth['jwt'], ['name' => 'Renamed']);
    $r->assertOk()->assertJsonPath('response.name', 'Renamed');
    Event::assertDispatched(OrganizationUpdated::class, 1);
});

it('DELETE /v1/organizations/{id} succeeds for an admin', function (): void {
    Event::fake([OrganizationDeleted::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    fapiAddMembership($f['env'], $org->id, $auth['user'], 'org:admin');

    $r = fapiOrgReq('DELETE', "/organizations/{$org->id}", $auth['jwt']);
    $r->assertOk()->assertJsonPath('response.deleted', true);
    expect(Organization::query()->withoutGlobalScopes()->where('id', $org->id)->exists())->toBeFalse();
    Event::assertDispatched(OrganizationDeleted::class, 1);
});

it('POST /v1/organizations/{id}/leave removes the caller membership', function (): void {
    Event::fake([OrganizationMembershipDeleted::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $admin = new User(['environment_id' => $f['env']->id, 'username' => 'other-admin']);
    $admin->save();

    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    fapiAddMembership($f['env'], $org->id, $admin, 'org:admin');
    fapiAddMembership($f['env'], $org->id, $auth['user'], 'org:member');

    $r = fapiOrgReq('POST', "/organizations/{$org->id}/leave", $auth['jwt']);
    $r->assertOk()->assertJsonPath('response.deleted', true);
    expect(OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $auth['user']->id)->exists())->toBeFalse();
    Event::assertDispatched(OrganizationMembershipDeleted::class, 1);
});

it('POST /v1/organizations/{id}/leave 422s for the last admin', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    fapiAddMembership($f['env'], $org->id, $auth['user'], 'org:admin');

    fapiOrgReq('POST', "/organizations/{$org->id}/leave", $auth['jwt'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'organization_last_admin');
});

it('POST /v1/organizations/{id}/memberships issues an invitation (not a direct membership)', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    fapiAddMembership($f['env'], $org->id, $auth['user'], 'org:admin');

    $r = fapiOrgReq('POST', "/organizations/{$org->id}/memberships", $auth['jwt'], [
        'email_address' => 'invitee@example.com',
        'role' => 'org:member',
    ]);
    $r->assertStatus(201)
        ->assertJsonPath('response.object', 'organization_invitation')
        ->assertJsonPath('response.email_address', 'invitee@example.com');
    expect($r->json('response.url'))->toContain('__authn_ticket=');
});

it('PATCH /v1/organizations/{id}/memberships/{user_id} updates role', function (): void {
    Event::fake([OrganizationMembershipUpdated::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $other = new User(['environment_id' => $f['env']->id, 'username' => 'other']);
    $other->save();

    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    fapiAddMembership($f['env'], $org->id, $auth['user'], 'org:admin');
    fapiAddMembership($f['env'], $org->id, $other, 'org:member');

    $r = fapiOrgReq('PATCH', "/organizations/{$org->id}/memberships/{$other->id}", $auth['jwt'], [
        'role' => 'org:admin',
    ]);
    $r->assertOk()->assertJsonPath('response.role', 'org:admin');
    Event::assertDispatched(OrganizationMembershipUpdated::class, 1);
});

it('DELETE /v1/organizations/{id}/memberships/{user_id} 422s on the last admin', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    fapiAddMembership($f['env'], $org->id, $auth['user'], 'org:admin');

    fapiOrgReq('DELETE', "/organizations/{$org->id}/memberships/{$auth['user']->id}", $auth['jwt'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'organization_last_admin');
});
