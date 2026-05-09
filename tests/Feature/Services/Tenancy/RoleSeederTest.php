<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\RoleSeeder;
use Illuminate\Support\Facades\Gate;

function makeSeederEnv(string $slug = 'env-seed'): Environment
{
    $project = Project::create(['name' => 'P '.$slug, 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('seeds 12 system permissions when an environment is created', function (): void {
    $env = makeSeederEnv();

    $perms = Permission::withoutGlobalScopes()->where('environment_id', $env->id)->get();
    expect($perms)->toHaveCount(13);
    expect($perms->pluck('key')->all())->toContain(
        'org:sys_profile:read',
        'org:sys_profile:manage',
        'org:sys_profile:delete',
        'org:sys_memberships:read',
        'org:sys_memberships:manage',
        'org:sys_domains:read',
        'org:sys_domains:manage',
        'org:sys_billing:read',
        'org:sys_billing:manage',
        'org:sys_sso:read',
        'org:sys_sso:manage',
        'org:sys_provisioning:read',
        'org:sys_provisioning:manage',
    );
    expect($perms->every(fn (Permission $p): bool => $p->is_system === true))->toBeTrue();
});

it('seeds the org:admin and org:member default roles with the right flags', function (): void {
    $env = makeSeederEnv();

    $admin = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:admin')->firstOrFail();
    $member = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:member')->firstOrFail();

    expect($admin->is_system)->toBeTrue();
    expect($admin->is_creator_eligible)->toBeTrue();
    expect($admin->is_default)->toBeFalse();
    expect($admin->permissions->count())->toBe(13);

    expect($member->is_system)->toBeTrue();
    expect($member->is_creator_eligible)->toBeFalse();
    expect($member->is_default)->toBeTrue();
    // Member only carries the read-only subset.
    $memberKeys = $member->permissions->pluck('key')->all();
    expect($memberKeys)->each->toEndWith(':read');
    expect($memberKeys)->toHaveCount(6);
});

it('is idempotent — re-running leaves one row per (env, key)', function (): void {
    $env = makeSeederEnv();

    app(RoleSeeder::class)->seed($env);
    app(RoleSeeder::class)->seed($env);

    expect(Permission::withoutGlobalScopes()->where('environment_id', $env->id)->count())->toBe(13);
    expect(Role::withoutGlobalScopes()->where('environment_id', $env->id)->count())->toBe(2);
});

it('does not bleed permissions across environments', function (): void {
    $envA = makeSeederEnv('a');
    $envB = makeSeederEnv('b');

    expect(Permission::withoutGlobalScopes()->where('environment_id', $envA->id)->count())->toBe(13);
    expect(Permission::withoutGlobalScopes()->where('environment_id', $envB->id)->count())->toBe(13);

    // Same key string lives in both envs as separate rows.
    $a = Permission::withoutGlobalScopes()->where('environment_id', $envA->id)->where('key', 'org:sys_profile:read')->firstOrFail();
    $b = Permission::withoutGlobalScopes()->where('environment_id', $envB->id)->where('key', 'org:sys_profile:read')->firstOrFail();
    expect($a->id)->not->toBe($b->id);
});

it('User::hasOrgPermission returns false when org is null', function (): void {
    $env = makeSeederEnv();
    $user = new User(['environment_id' => $env->id]);
    $user->save();

    expect($user->hasOrgPermission('org:sys_profile:read', null))->toBeFalse();
});

it('User::hasOrgPermission returns true through membership → role → permission', function (): void {
    $env = makeSeederEnv();
    $user = new User(['environment_id' => $env->id]);
    $user->save();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'A', 'slug' => 'a']);
    $admin = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:admin')->firstOrFail();

    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $admin->id,
    ]);

    expect($user->hasOrgPermission('org:sys_memberships:manage', $org))->toBeTrue();
    expect($user->hasOrgPermission('org:sys_profile:read', $org))->toBeTrue();
});

it('User::hasOrgPermission returns false when the role does not carry the key', function (): void {
    $env = makeSeederEnv();
    $user = new User(['environment_id' => $env->id]);
    $user->save();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'A', 'slug' => 'a']);
    $member = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:member')->firstOrFail();

    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $member->id,
    ]);

    // Member is read-only.
    expect($user->hasOrgPermission('org:sys_memberships:manage', $org))->toBeFalse();
    expect($user->hasOrgPermission('org:sys_memberships:read', $org))->toBeTrue();
});

it('User::hasOrgPermission returns false when the user is not a member of the org', function (): void {
    $env = makeSeederEnv();
    $userA = new User(['environment_id' => $env->id, 'username' => 'a']);
    $userA->save();
    $userB = new User(['environment_id' => $env->id, 'username' => 'b']);
    $userB->save();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'A', 'slug' => 'a']);
    $admin = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:admin')->firstOrFail();

    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $userA->id,
        'role_id' => $admin->id,
    ]);

    expect($userB->hasOrgPermission('org:sys_profile:read', $org))->toBeFalse();
});

it('User::hasOrgRole returns true on exact role-key match', function (): void {
    $env = makeSeederEnv();
    $user = new User(['environment_id' => $env->id]);
    $user->save();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'A', 'slug' => 'a']);
    $admin = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:admin')->firstOrFail();

    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $admin->id,
    ]);

    expect($user->hasOrgRole('org:admin', $org))->toBeTrue();
    expect($user->hasOrgRole('org:member', $org))->toBeFalse();
    expect($user->hasOrgRole('org:admin', null))->toBeFalse();
});

it('reflects pivot updates after flushing the per-request cache', function (): void {
    $env = makeSeederEnv();
    $user = new User(['environment_id' => $env->id]);
    $user->save();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'A', 'slug' => 'a']);
    $member = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:member')->firstOrFail();

    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $member->id,
    ]);

    expect($user->hasOrgPermission('org:sys_memberships:manage', $org))->toBeFalse();

    // Add the manage permission to the member role.
    $managePerm = Permission::withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('key', 'org:sys_memberships:manage')
        ->firstOrFail();
    $member->permissions()->attach($managePerm->id);
    $user->flushOrgPermissionCache();

    expect($user->hasOrgPermission('org:sys_memberships:manage', $org))->toBeTrue();
});

it('Gate::allows dispatches through hasOrgPermission for system abilities', function (): void {
    $env = makeSeederEnv();
    $user = new User(['environment_id' => $env->id]);
    $user->save();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'A', 'slug' => 'a']);
    $admin = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:admin')->firstOrFail();

    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $admin->id,
    ]);

    expect(Gate::forUser($user)->allows('org:sys_memberships:manage', $org))->toBeTrue();
    expect(Gate::forUser($user)->allows('org:sys_billing:manage', $org))->toBeTrue();
});
