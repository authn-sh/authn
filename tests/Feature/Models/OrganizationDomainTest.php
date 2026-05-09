<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\OrganizationMembershipRequest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeOrgEnv(string $slug = 'env-org'): Environment
{
    $project = Project::create(['name' => 'P '.$slug, 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

function makeOrgUser(Environment $env, string $emailLocal): User
{
    $u = new User(['environment_id' => $env->id, 'first_name' => $emailLocal]);
    $u->save();

    return $u;
}

it('creates an Organization with a prefixed ULID and v0.2 columns', function (): void {
    $env = makeOrgEnv();

    $org = Organization::create([
        'environment_id' => $env->id,
        'name' => 'Acme',
        'slug' => 'acme',
        'public_metadata' => ['plan' => 'pro'],
        'private_metadata' => ['internal' => 'x'],
        'max_allowed_memberships' => 50,
    ])->fresh();

    expect($org->id)->toStartWith('org_');
    expect($org->members_count)->toBe(0);
    expect($org->pending_invitations_count)->toBe(0);
    expect($org->admin_delete_enabled)->toBeTrue();
    expect($org->public_metadata)->toBe(['plan' => 'pro']);
    expect($org->max_allowed_memberships)->toBe(50);
    expect($org->toArray())->not->toHaveKey('private_metadata');
});

it('enforces unique slug per environment for organizations', function (): void {
    $envA = makeOrgEnv('a');
    $envB = makeOrgEnv('b');

    Organization::create(['environment_id' => $envA->id, 'name' => 'Acme', 'slug' => 'acme']);
    // Same slug in a different env is fine.
    Organization::create(['environment_id' => $envB->id, 'name' => 'Acme', 'slug' => 'acme']);

    expect(fn () => Organization::create([
        'environment_id' => $envA->id,
        'name' => 'Dup',
        'slug' => 'acme',
    ]))->toThrow(QueryException::class);
});

it('creates a Role + Permission and links them through role_permission', function (): void {
    $env = makeOrgEnv();

    $perm = Permission::create([
        'environment_id' => $env->id,
        'key' => 'org:sys_profile:manage',
        'name' => 'Manage organization profile',
        'is_system' => true,
    ]);
    $role = Role::create([
        'environment_id' => $env->id,
        'key' => 'org:admin',
        'name' => 'Organization admin',
        'is_creator_eligible' => true,
        'is_system' => true,
    ]);

    $role->permissions()->attach($perm->id);

    expect($role->id)->toStartWith('role_');
    expect($perm->id)->toStartWith('perm_');
    expect($role->permissions->pluck('key')->all())->toBe(['org:sys_profile:manage']);
    expect($perm->roles->pluck('key')->all())->toBe(['org:admin']);
});

it('enforces unique role keys per environment', function (): void {
    $env = makeOrgEnv();

    Role::create(['environment_id' => $env->id, 'key' => 'org:admin', 'name' => 'A']);
    expect(fn () => Role::create([
        'environment_id' => $env->id, 'key' => 'org:admin', 'name' => 'B',
    ]))->toThrow(QueryException::class);
});

it('enforces unique permission keys per environment', function (): void {
    $env = makeOrgEnv();

    Permission::create(['environment_id' => $env->id, 'key' => 'org:x:read', 'name' => 'X']);
    expect(fn () => Permission::create([
        'environment_id' => $env->id, 'key' => 'org:x:read', 'name' => 'Y',
    ]))->toThrow(QueryException::class);
});

it('exposes membership.permissionKeys() through the role linkage', function (): void {
    $env = makeOrgEnv();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    $user = makeOrgUser($env, 'alice');

    $perm = Permission::create([
        'environment_id' => $env->id, 'key' => 'org:sys_memberships:read', 'name' => 'X',
    ]);
    $role = Role::create([
        'environment_id' => $env->id, 'key' => 'org:member', 'name' => 'Member',
    ]);
    $role->permissions()->attach($perm->id);

    $mem = OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => OrganizationMembership::ROLE_MEMBER,
        'role_id' => $role->id,
    ]);

    expect($mem->id)->toStartWith('orgmem_');
    expect($mem->fresh()->permissionKeys())->toBe(['org:sys_memberships:read']);
});

it('legacy memberships without role_id return an empty permissionKeys list', function (): void {
    $env = makeOrgEnv();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    $user = makeOrgUser($env, 'a');

    $mem = OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => OrganizationMembership::ROLE_WORKSPACE_OWNER,
    ]);

    expect($mem->permissionKeys())->toBe([]);
});

it('creates an OrganizationInvitation and round-trips relations', function (): void {
    $env = makeOrgEnv();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    $inviter = makeOrgUser($env, 'admin');
    $role = Role::create(['environment_id' => $env->id, 'key' => 'org:member', 'name' => 'Member']);

    $inv = OrganizationInvitation::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'email_address' => 'newbie@example.com',
        'role_id' => $role->id,
        'inviter_user_id' => $inviter->id,
        'redirect_url' => 'https://example.com/welcome',
        'status' => OrganizationInvitation::STATUS_PENDING,
        'public_metadata' => ['source' => 'dashboard'],
        'expires_at' => now()->addDays(7),
    ]);

    expect($inv->id)->toStartWith('orginv_');
    expect($inv->organization->is($org))->toBeTrue();
    expect($inv->role->is($role))->toBeTrue();
    expect($inv->inviter->is($inviter))->toBeTrue();
    expect($org->fresh()->invitations->pluck('id')->all())->toBe([$inv->id]);
});

it('creates an OrganizationDomain unique per env', function (): void {
    $envA = makeOrgEnv('a');
    $envB = makeOrgEnv('b');
    $orgA = Organization::create(['environment_id' => $envA->id, 'name' => 'A', 'slug' => 'a']);
    $orgB = Organization::create(['environment_id' => $envB->id, 'name' => 'B', 'slug' => 'b']);

    $d = OrganizationDomain::create([
        'environment_id' => $envA->id,
        'organization_id' => $orgA->id,
        'name' => 'acme.test',
        'enrollment_mode' => OrganizationDomain::MODE_AUTOMATIC_INVITATION,
    ]);
    expect($d->id)->toStartWith('orgdom_');

    OrganizationDomain::create([
        'environment_id' => $envB->id,
        'organization_id' => $orgB->id,
        'name' => 'acme.test',
    ]);

    expect(fn () => OrganizationDomain::create([
        'environment_id' => $envA->id,
        'organization_id' => $orgA->id,
        'name' => 'acme.test',
    ]))->toThrow(QueryException::class);
});

it('creates an OrganizationMembershipRequest and enforces uniqueness', function (): void {
    $env = makeOrgEnv();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'A', 'slug' => 'a']);
    $user = makeOrgUser($env, 'u');

    $req = OrganizationMembershipRequest::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => OrganizationMembershipRequest::STATUS_PENDING,
    ]);
    expect($req->id)->toStartWith('orgreq_');

    expect(fn () => OrganizationMembershipRequest::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => OrganizationMembershipRequest::STATUS_PENDING,
    ]))->toThrow(QueryException::class);
});

it('cascades org-scoped rows when their Organization is deleted', function (): void {
    $env = makeOrgEnv();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'A', 'slug' => 'a']);
    $other = Organization::create(['environment_id' => $env->id, 'name' => 'B', 'slug' => 'b']);
    $user = makeOrgUser($env, 'u');
    $role = Role::create(['environment_id' => $env->id, 'key' => 'org:member', 'name' => 'M']);

    OrganizationMembership::create([
        'environment_id' => $env->id, 'organization_id' => $org->id, 'user_id' => $user->id,
        'role' => 'org:member', 'role_id' => $role->id,
    ]);
    OrganizationInvitation::create([
        'environment_id' => $env->id, 'organization_id' => $org->id,
        'email_address' => 'x@example.com', 'role_id' => $role->id,
        'status' => 'pending',
    ]);
    OrganizationDomain::create([
        'environment_id' => $env->id, 'organization_id' => $org->id, 'name' => 'acme.test',
    ]);
    OrganizationMembershipRequest::create([
        'environment_id' => $env->id, 'organization_id' => $org->id, 'user_id' => $user->id,
        'status' => 'pending',
    ]);
    // Sibling org keeps a row to prove no cross-pollution.
    OrganizationDomain::create([
        'environment_id' => $env->id, 'organization_id' => $other->id, 'name' => 'sibling.test',
    ]);

    $org->delete();

    expect(OrganizationMembership::where('organization_id', $org->id)->exists())->toBeFalse();
    expect(OrganizationInvitation::where('organization_id', $org->id)->exists())->toBeFalse();
    expect(OrganizationDomain::where('organization_id', $org->id)->exists())->toBeFalse();
    expect(OrganizationMembershipRequest::where('organization_id', $org->id)->exists())->toBeFalse();
    expect(OrganizationDomain::where('organization_id', $other->id)->exists())->toBeTrue();
});

it('cascades roles + permissions + pivot when their Environment is deleted', function (): void {
    $envA = makeOrgEnv('a');
    $envB = makeOrgEnv('b');

    $permA = Permission::create(['environment_id' => $envA->id, 'key' => 'p:a', 'name' => 'A']);
    $roleA = Role::create(['environment_id' => $envA->id, 'key' => 'r:a', 'name' => 'A']);
    $roleA->permissions()->attach($permA->id);

    $permB = Permission::create(['environment_id' => $envB->id, 'key' => 'p:b', 'name' => 'B']);
    $roleB = Role::create(['environment_id' => $envB->id, 'key' => 'r:b', 'name' => 'B']);
    $roleB->permissions()->attach($permB->id);

    // Delete via project, which cascades through environment.
    Project::find($envA->project_id)->delete();

    expect(Role::withoutGlobalScopes()->where('id', $roleA->id)->exists())->toBeFalse();
    expect(Permission::withoutGlobalScopes()->where('id', $permA->id)->exists())->toBeFalse();
    expect(Role::withoutGlobalScopes()->where('id', $roleB->id)->exists())->toBeTrue();
    expect(Permission::withoutGlobalScopes()->where('id', $permB->id)->exists())->toBeTrue();
});
