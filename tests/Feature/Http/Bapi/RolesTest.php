<?php

declare(strict_types=1);

use App\Events\Organizations\RoleCreated;
use App\Events\Organizations\RoleDeleted;
use App\Events\Organizations\RolePermissionsChanged;
use App\Events\Organizations\RoleUpdated;
use App\Models\Environment;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Http\Bapi\BapiTestSupport;

uses(RefreshDatabase::class);

it('GET /v1/roles lists system roles seeded by AU-2', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))->getJson(BapiTestSupport::url('/roles'));
    $r->assertOk();
    $keys = array_column($r->json('data'), 'key');
    expect($keys)->toContain('org:admin', 'org:member');
});

it('GET /v1/roles?is_system=false returns nothing on a fresh env', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))->getJson(BapiTestSupport::url('/roles?is_system=false'));
    $r->assertOk()->assertJsonPath('total_count', 0);
});

it('POST /v1/roles creates a custom role with permissions and fires RoleCreated', function (): void {
    Event::fake([RoleCreated::class]);
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))->postJson(BapiTestSupport::url('/roles'), [
        'key' => 'org:billing_admin',
        'name' => 'Billing admin',
        'description' => 'Manages org billing',
        'permissions' => ['org:sys_billing:read', 'org:sys_billing:manage'],
    ]);
    $r->assertStatus(201)
        ->assertJsonPath('object', 'role')
        ->assertJsonPath('key', 'org:billing_admin')
        ->assertJsonPath('is_system', false);
    expect($r->json('permissions'))->toContain('org:sys_billing:read', 'org:sys_billing:manage');
    Event::assertDispatched(RoleCreated::class, 1);
});

it('POST /v1/roles 409s when key is reserved (org:admin / org:member)', function (): void {
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);

    $this->withHeaders($headers)->postJson(BapiTestSupport::url('/roles'), [
        'key' => 'org:admin', 'name' => 'X',
    ])->assertStatus(409);
    $this->withHeaders($headers)->postJson(BapiTestSupport::url('/roles'), [
        'key' => 'org:member', 'name' => 'X',
    ])->assertStatus(409);
});

it('POST /v1/roles 422s on a malformed key', function (): void {
    $f = BapiTestSupport::bootEnv();
    $this->withHeaders(BapiTestSupport::headers($f['token']))->postJson(BapiTestSupport::url('/roles'), [
        'key' => 'BAD', 'name' => 'X',
    ])->assertStatus(422);
});

it('POST /v1/roles 422s on unknown permission keys', function (): void {
    $f = BapiTestSupport::bootEnv();
    $this->withHeaders(BapiTestSupport::headers($f['token']))->postJson(BapiTestSupport::url('/roles'), [
        'key' => 'org:custom_one', 'name' => 'X',
        'permissions' => ['org:sys_billing:read', 'org:nonsense:thing'],
    ])->assertStatus(422);
});

it('PATCH /v1/roles/{id} updates name + description, fires RoleUpdated', function (): void {
    Event::fake([RoleUpdated::class]);
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);
    $created = $this->withHeaders($headers)->postJson(BapiTestSupport::url('/roles'), [
        'key' => 'org:custom', 'name' => 'Old',
    ]);
    $id = $created->json('id');

    $r = $this->withHeaders($headers)->patchJson(BapiTestSupport::url("/roles/{$id}"), [
        'name' => 'New',
        'description' => 'Custom role',
    ]);
    $r->assertOk()->assertJsonPath('name', 'New')->assertJsonPath('description', 'Custom role');
    Event::assertDispatched(RoleUpdated::class, 1);
});

it('PATCH refuses to mutate system roles', function (): void {
    $f = BapiTestSupport::bootEnv();
    $admin = Role::withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:admin')->firstOrFail();
    $this->withHeaders(BapiTestSupport::headers($f['token']))->patchJson(BapiTestSupport::url("/roles/{$admin->id}"), [
        'name' => 'Hacked',
    ])->assertStatus(422);
});

it('DELETE refuses to delete system roles', function (): void {
    $f = BapiTestSupport::bootEnv();
    $admin = Role::withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:admin')->firstOrFail();
    $this->withHeaders(BapiTestSupport::headers($f['token']))->deleteJson(BapiTestSupport::url("/roles/{$admin->id}"))->assertStatus(422);
});

it('DELETE reassigns memberships to the env default role', function (): void {
    Event::fake([RoleDeleted::class]);
    /** @var Environment $env */
    $f = BapiTestSupport::bootEnv();
    $env = $f['env'];
    $headers = BapiTestSupport::headers($f['token']);

    $created = $this->withHeaders($headers)->postJson(BapiTestSupport::url('/roles'), [
        'key' => 'org:custom_role', 'name' => 'Custom',
    ]);
    $customRoleId = $created->json('id');

    $org = $this->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'Acme'])->json('id');
    $user = new User(['environment_id' => $env->id, 'username' => 'reassign']);
    $user->save();
    $membership = OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org,
        'user_id' => $user->id,
        'role_id' => $customRoleId,
    ]);

    $this->withHeaders($headers)->deleteJson(BapiTestSupport::url("/roles/{$customRoleId}"))
        ->assertOk()->assertJsonPath('deleted', true);

    $defaultRole = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('is_default', true)->firstOrFail();
    expect($membership->fresh()->role_id)->toBe($defaultRole->id);
    Event::assertDispatched(RoleDeleted::class, 1);
});

it('PUT /v1/roles/{id}/permissions replaces the permission set in one transaction', function (): void {
    Event::fake([RolePermissionsChanged::class]);
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);
    $created = $this->withHeaders($headers)->postJson(BapiTestSupport::url('/roles'), [
        'key' => 'org:permset', 'name' => 'PermSet',
        'permissions' => ['org:sys_billing:read'],
    ]);
    $id = $created->json('id');

    $r = $this->withHeaders($headers)->putJson(BapiTestSupport::url("/roles/{$id}/permissions"), [
        'permissions' => ['org:sys_profile:read', 'org:sys_profile:manage'],
    ]);
    $r->assertOk();
    expect($r->json('permissions'))
        ->toContain('org:sys_profile:read', 'org:sys_profile:manage')
        ->not->toContain('org:sys_billing:read');
    Event::assertDispatched(RolePermissionsChanged::class, 1);
});

it('PUT /permissions on a system role 422s', function (): void {
    $f = BapiTestSupport::bootEnv();
    $admin = Role::withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:admin')->firstOrFail();
    $this->withHeaders(BapiTestSupport::headers($f['token']))->putJson(BapiTestSupport::url("/roles/{$admin->id}/permissions"), [
        'permissions' => [],
    ])->assertStatus(422);
});

it('GET /v1/permissions returns the seeded system permissions', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))->getJson(BapiTestSupport::url('/permissions'));
    $r->assertOk()->assertJsonPath('total_count', 13);
    expect(array_column($r->json('data'), 'key'))->toContain(
        'org:sys_profile:read', 'org:sys_profile:manage', 'org:sys_profile:delete',
        'org:sys_memberships:manage', 'org:sys_billing:manage',
    );
});

it('GET /v1/permissions?is_system=false returns nothing on a fresh env', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))->getJson(BapiTestSupport::url('/permissions?is_system=false'));
    $r->assertOk()->assertJsonPath('total_count', 0);
});
