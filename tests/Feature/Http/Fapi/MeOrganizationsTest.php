<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationInvitationAccepted;
use App\Events\Organizations\OrganizationInvitationCreated;
use App\Events\Organizations\OrganizationInvitationRevoked;
use App\Events\Organizations\OrganizationMembershipCreated;
use App\Events\Organizations\OrganizationMembershipRequestApproved;
use App\Events\Organizations\OrganizationMembershipRequestRejected;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\OrganizationMembershipRequest;
use App\Models\Role;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

uses(RefreshDatabase::class);

function meOrgReq(string $method, string $path, string $jwt, array $body = [], string $origin = 'https://app.example.com'): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => $origin,
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

function makeOrgWithMembership(Environment $env, User $user, string $roleKey, string $slug = 'acme'): Organization
{
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => $slug]);
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
    Organization::query()->withoutGlobalScopes()->where('id', $org->id)->increment('members_count');

    return $org->fresh();
}

it('GET /v1/me/organization_memberships lists the caller memberships', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    makeOrgWithMembership($f['env'], $auth['user'], 'org:admin', 'a');
    makeOrgWithMembership($f['env'], $auth['user'], 'org:member', 'b');

    $r = meOrgReq('GET', '/me/organization_memberships', $auth['jwt']);
    $r->assertOk()->assertJsonPath('total_count', 2);
});

it('GET /v1/me/organization_invitations lists pending invitations addressed to the caller', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'A', 'slug' => 'a']);
    $role = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:member')->firstOrFail();
    OrganizationInvitation::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'email_address' => 'alice@example.com',
        'role_id' => $role->id,
        'status' => OrganizationInvitation::STATUS_PENDING,
    ]);

    $r = meOrgReq('GET', '/me/organization_invitations', $auth['jwt']);
    $r->assertOk()->assertJsonPath('total_count', 1)->assertJsonPath('data.0.email_address', 'alice@example.com');
});

it('POST /v1/me/organization_invitations/{id}/accept creates a membership and fires events', function (): void {
    Event::fake([OrganizationInvitationAccepted::class, OrganizationMembershipCreated::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'A', 'slug' => 'a']);
    $role = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:member')->firstOrFail();
    $invitation = OrganizationInvitation::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'email_address' => 'alice@example.com',
        'role_id' => $role->id,
        'status' => OrganizationInvitation::STATUS_PENDING,
    ]);
    Organization::query()->withoutGlobalScopes()->where('id', $org->id)->increment('pending_invitations_count');

    $r = meOrgReq('POST', "/me/organization_invitations/{$invitation->id}/accept", $auth['jwt']);
    $r->assertOk()->assertJsonPath('response.object', 'organization_membership');
    expect(OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $auth['user']->id)->exists())->toBeTrue();
    expect($invitation->fresh()->status)->toBe('accepted');

    Event::assertDispatched(OrganizationInvitationAccepted::class, 1);
    Event::assertDispatched(OrganizationMembershipCreated::class, 1);
});

it('POST /accept on an already-accepted invitation 409s', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'A', 'slug' => 'a']);
    $role = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:member')->firstOrFail();
    $invitation = OrganizationInvitation::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'email_address' => 'alice@example.com',
        'role_id' => $role->id,
        'status' => OrganizationInvitation::STATUS_ACCEPTED,
    ]);

    meOrgReq('POST', "/me/organization_invitations/{$invitation->id}/accept", $auth['jwt'])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'organization_invitation_already_accepted');
});

it('POST /accept on an invitation for someone else 404s', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'A', 'slug' => 'a']);
    $role = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:member')->firstOrFail();
    $invitation = OrganizationInvitation::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'email_address' => 'someone-else@example.com',
        'role_id' => $role->id,
        'status' => OrganizationInvitation::STATUS_PENDING,
    ]);

    meOrgReq('POST', "/me/organization_invitations/{$invitation->id}/accept", $auth['jwt'])
        ->assertStatus(404);
});

it('GET /v1/me/organization_membership_requests lists my requests', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'A', 'slug' => 'a']);
    OrganizationMembershipRequest::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'user_id' => $auth['user']->id,
        'status' => 'pending',
    ]);

    meOrgReq('GET', '/me/organization_membership_requests', $auth['jwt'])
        ->assertOk()->assertJsonPath('total_count', 1);
});

it('PUT /v1/me/active_organization updates Session.last_active_organization_id', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = makeOrgWithMembership($f['env'], $auth['user'], 'org:member');

    $r = meOrgReq('PUT', '/me/active_organization', $auth['jwt'], ['organization_id' => $org->id]);
    $r->assertOk()->assertJsonPath('response.organization_id', $org->id);

    $session = Session::query()->withoutGlobalScopes()->where('id', $auth['session']->id)->firstOrFail();
    expect($session->last_active_organization_id)->toBe($org->id);
});

it('PUT /v1/me/active_organization with null clears the active org', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = makeOrgWithMembership($f['env'], $auth['user'], 'org:admin');
    Session::query()->withoutGlobalScopes()->where('id', $auth['session']->id)->update(['last_active_organization_id' => $org->id]);

    $r = meOrgReq('PUT', '/me/active_organization', $auth['jwt'], ['organization_id' => null]);
    $r->assertOk()->assertJsonPath('response.organization_id', null);
});

it('PUT /v1/me/active_organization 404s for an org the user is not in', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $other = new User(['environment_id' => $f['env']->id, 'username' => 'other']);
    $other->save();
    $org = makeOrgWithMembership($f['env'], $other, 'org:admin');

    meOrgReq('PUT', '/me/active_organization', $auth['jwt'], ['organization_id' => $org->id])
        ->assertStatus(404);
});

it('GET /v1/organizations/{id}/invitations gates on org:sys_memberships:read', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = makeOrgWithMembership($f['env'], $auth['user'], 'org:admin');

    meOrgReq('GET', "/organizations/{$org->id}/invitations", $auth['jwt'])->assertOk();
});

it('POST /v1/organizations/{id}/invitations creates an invitation and fires the event', function (): void {
    Event::fake([OrganizationInvitationCreated::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = makeOrgWithMembership($f['env'], $auth['user'], 'org:admin');

    $r = meOrgReq('POST', "/organizations/{$org->id}/invitations", $auth['jwt'], [
        'email_address' => 'newbie@example.com',
        'role' => 'org:member',
    ]);
    $r->assertStatus(201)->assertJsonPath('response.object', 'organization_invitation');
    expect($r->json('response.url'))->toContain('__authn_ticket=');
    Event::assertDispatched(OrganizationInvitationCreated::class, 1);
});

it('POST /v1/organizations/{id}/invitations/bulk creates atomically', function (): void {
    Event::fake([OrganizationInvitationCreated::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = makeOrgWithMembership($f['env'], $auth['user'], 'org:admin');

    $r = meOrgReq('POST', "/organizations/{$org->id}/invitations/bulk", $auth['jwt'], [
        'invitations' => [
            ['email_address' => 'one@example.com', 'role' => 'org:member'],
            ['email_address' => 'two@example.com', 'role' => 'org:member'],
        ],
    ]);
    $r->assertStatus(201)->assertJsonPath('response.created', 2);
    Event::assertDispatched(OrganizationInvitationCreated::class, 2);
});

it('POST /v1/organizations/{id}/invitations/{id}/revoke flips status', function (): void {
    Event::fake([OrganizationInvitationRevoked::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = makeOrgWithMembership($f['env'], $auth['user'], 'org:admin');
    $role = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:member')->firstOrFail();
    $invitation = OrganizationInvitation::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'email_address' => 'revoke-me@example.com',
        'role_id' => $role->id,
        'status' => 'pending',
    ]);
    Organization::query()->withoutGlobalScopes()->where('id', $org->id)->increment('pending_invitations_count');

    $r = meOrgReq('POST', "/organizations/{$org->id}/invitations/{$invitation->id}/revoke", $auth['jwt']);
    $r->assertOk()->assertJsonPath('response.status', 'revoked');
    Event::assertDispatched(OrganizationInvitationRevoked::class, 1);
});

it('POST /v1/organizations/{id}/membership_requests/{rid}/accept creates a membership', function (): void {
    Event::fake([OrganizationMembershipRequestApproved::class, OrganizationMembershipCreated::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = makeOrgWithMembership($f['env'], $auth['user'], 'org:admin');

    $applicant = new User(['environment_id' => $f['env']->id, 'username' => 'applicant']);
    $applicant->save();
    $req = OrganizationMembershipRequest::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'user_id' => $applicant->id,
        'status' => 'pending',
    ]);

    $r = meOrgReq('POST', "/organizations/{$org->id}/membership_requests/{$req->id}/accept", $auth['jwt']);
    $r->assertOk()->assertJsonPath('response.status', 'accepted');
    expect(OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $applicant->id)->exists())->toBeTrue();

    Event::assertDispatched(OrganizationMembershipRequestApproved::class, 1);
    Event::assertDispatched(OrganizationMembershipCreated::class, 1);
});

it('POST /v1/organizations/{id}/membership_requests/{rid}/reject flips status', function (): void {
    Event::fake([OrganizationMembershipRequestRejected::class]);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = makeOrgWithMembership($f['env'], $auth['user'], 'org:admin');

    $applicant = new User(['environment_id' => $f['env']->id, 'username' => 'applicant']);
    $applicant->save();
    $req = OrganizationMembershipRequest::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'user_id' => $applicant->id,
        'status' => 'pending',
    ]);

    $r = meOrgReq('POST', "/organizations/{$org->id}/membership_requests/{$req->id}/reject", $auth['jwt']);
    $r->assertOk()->assertJsonPath('response.status', 'revoked');
    Event::assertDispatched(OrganizationMembershipRequestRejected::class, 1);
});

it('non-admin members cannot create invitations on an org (sys_memberships:manage required)', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = makeOrgWithMembership($f['env'], $auth['user'], 'org:member');

    meOrgReq('POST', "/organizations/{$org->id}/invitations", $auth['jwt'], [
        'email_address' => 'someone@example.com', 'role' => 'org:member',
    ])->assertStatus(403);
});

it('non-members cannot list invitations (404, privacy default)', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $other = new User(['environment_id' => $f['env']->id, 'username' => 'other']);
    $other->save();
    $org = makeOrgWithMembership($f['env'], $other, 'org:admin');

    meOrgReq('GET', "/organizations/{$org->id}/invitations", $auth['jwt'])
        ->assertStatus(404);
});
