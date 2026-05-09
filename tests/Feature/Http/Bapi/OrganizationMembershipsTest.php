<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationMembershipCreated;
use App\Events\Organizations\OrganizationMembershipDeleted;
use App\Events\Organizations\OrganizationMembershipUpdated;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Http\Bapi\BapiTestSupport;
use Tests\TestCase;

/**
 * @return array{env: Environment, token: string, headers: array, org_id: string, admin: User, member: User, other: User}
 */
function setupOrgWithAdmin(TestCase $testCase): array
{
    $f = BapiTestSupport::bootEnv();
    /** @var Environment $env */
    $env = $f['env'];
    $headers = BapiTestSupport::headers($f['token']);

    $r = $testCase->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'Acme']);
    $orgId = $r->json('id');

    $admin = new User(['environment_id' => $env->id, 'username' => 'admin']);
    $admin->save();
    $member = new User(['environment_id' => $env->id, 'username' => 'member']);
    $member->save();
    $other = new User(['environment_id' => $env->id, 'username' => 'other']);
    $other->save();

    $adminRole = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:admin')->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $orgId,
        'user_id' => $admin->id,
        'role_id' => $adminRole->id,
    ]);
    Organization::query()->withoutGlobalScopes()->where('id', $orgId)->increment('members_count');

    return [
        'env' => $env,
        'token' => $f['token'],
        'headers' => $headers,
        'org_id' => $orgId,
        'admin' => $admin,
        'member' => $member,
        'other' => $other,
    ];
}

it('POST /memberships adds a user, increments members_count, fires the event', function (): void {
    Event::fake([OrganizationMembershipCreated::class]);
    $ctx = setupOrgWithAdmin($this);

    $r = $this->withHeaders($ctx['headers'])
        ->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships"), [
            'user_id' => $ctx['member']->id,
            'role' => 'org:member',
        ]);
    $r->assertStatus(201)
        ->assertJsonPath('object', 'organization_membership')
        ->assertJsonPath('role', 'org:member')
        ->assertJsonPath('public_user_data.user_id', $ctx['member']->id);
    expect($r->json('id'))->toStartWith('orgmem_');

    $org = Organization::query()->withoutGlobalScopes()->where('id', $ctx['org_id'])->firstOrFail();
    expect($org->members_count)->toBe(2);
    Event::assertDispatched(OrganizationMembershipCreated::class, 1);
});

it('POST /memberships 409s on duplicate (org_id, user_id)', function (): void {
    $ctx = setupOrgWithAdmin($this);
    $body = ['user_id' => $ctx['member']->id, 'role' => 'org:member'];

    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships"), $body)->assertStatus(201);
    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships"), $body)->assertStatus(409);
});

it('POST /memberships 422s when role key is unknown', function (): void {
    $ctx = setupOrgWithAdmin($this);
    $this->withHeaders($ctx['headers'])
        ->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships"), [
            'user_id' => $ctx['member']->id,
            'role' => 'org:nonsense',
        ])->assertStatus(422);
});

it('POST /memberships 422s when the org is at its membership cap', function (): void {
    $ctx = setupOrgWithAdmin($this);
    Organization::query()->withoutGlobalScopes()->where('id', $ctx['org_id'])->update([
        'max_allowed_memberships' => 1,
    ]);

    $this->withHeaders($ctx['headers'])
        ->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships"), [
            'user_id' => $ctx['member']->id,
            'role' => 'org:member',
        ])->assertStatus(422);
});

it('GET /memberships paginates and surfaces public_user_data', function (): void {
    $ctx = setupOrgWithAdmin($this);

    foreach ([$ctx['member'], $ctx['other']] as $u) {
        $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships"), [
            'user_id' => $u->id,
            'role' => 'org:member',
        ])->assertStatus(201);
    }

    $r = $this->withHeaders($ctx['headers'])->getJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships"));
    $r->assertOk()->assertJsonPath('total_count', 3);
    expect($r->json('data.0.public_user_data'))->not->toBeNull();
});

it('PATCH /memberships/{user_id} updates role and fires the event', function (): void {
    Event::fake([OrganizationMembershipUpdated::class]);
    $ctx = setupOrgWithAdmin($this);
    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships"), [
        'user_id' => $ctx['member']->id,
        'role' => 'org:member',
    ])->assertStatus(201);

    $r = $this->withHeaders($ctx['headers'])->patchJson(
        BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships/{$ctx['member']->id}"),
        ['role' => 'org:admin'],
    );
    $r->assertOk()->assertJsonPath('role', 'org:admin');
    Event::assertDispatched(OrganizationMembershipUpdated::class, 1);
});

it('PATCH refuses to demote the last admin', function (): void {
    $ctx = setupOrgWithAdmin($this);
    $this->withHeaders($ctx['headers'])->patchJson(
        BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships/{$ctx['admin']->id}"),
        ['role' => 'org:member'],
    )->assertStatus(422);
});

it('DELETE /memberships/{user_id} removes the row, decrements counter, fires event', function (): void {
    Event::fake([OrganizationMembershipDeleted::class]);
    $ctx = setupOrgWithAdmin($this);
    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships"), [
        'user_id' => $ctx['member']->id,
        'role' => 'org:member',
    ])->assertStatus(201);

    $r = $this->withHeaders($ctx['headers'])->deleteJson(
        BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships/{$ctx['member']->id}"),
    );
    $r->assertOk()->assertJsonPath('deleted', true);

    $org = Organization::query()->withoutGlobalScopes()->where('id', $ctx['org_id'])->firstOrFail();
    expect($org->members_count)->toBe(1);
    Event::assertDispatched(OrganizationMembershipDeleted::class, 1);
});

it('DELETE refuses to remove the last admin', function (): void {
    $ctx = setupOrgWithAdmin($this);
    $this->withHeaders($ctx['headers'])->deleteJson(
        BapiTestSupport::url("/organizations/{$ctx['org_id']}/memberships/{$ctx['admin']->id}"),
    )->assertStatus(422);
});
