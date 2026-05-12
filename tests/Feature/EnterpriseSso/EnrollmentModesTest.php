<?php

declare(strict_types=1);

use App\Auth\EnterpriseSso\EnterpriseConnectionService;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

function makeEnvForEnrollment(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('routes to the org-scoped connection when the verified domain has automatic_invitation', function (): void {
    $env = makeEnvForEnrollment();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    OrganizationDomain::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'name' => 'acme.test',
        'verified' => true,
        'enrollment_mode' => OrganizationDomain::MODE_AUTOMATIC_INVITATION,
    ]);
    $conn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'domains' => ['acme.test'],
    ]);

    $found = (new EnterpriseConnectionService)->findByIdentifierDomain($env, 'alice@acme.test');
    expect($found?->id)->toBe($conn->id);
});

it('still routes when enrollment_mode is automatic_suggestion (the join is deferred to user opt-in)', function (): void {
    $env = makeEnvForEnrollment();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    OrganizationDomain::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'name' => 'acme.test',
        'verified' => true,
        'enrollment_mode' => OrganizationDomain::MODE_AUTOMATIC_SUGGESTION,
    ]);
    $conn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'domains' => ['acme.test'],
    ]);

    $found = (new EnterpriseConnectionService)->findByIdentifierDomain($env, 'alice@acme.test');
    expect($found?->id)->toBe($conn->id);
});

it('treats manual_invitation as the same lookup as automatic_*: domain routing happens regardless', function (): void {
    $env = makeEnvForEnrollment();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    OrganizationDomain::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'name' => 'acme.test',
        'verified' => true,
        'enrollment_mode' => OrganizationDomain::MODE_MANUAL_INVITATION,
    ]);
    EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'domains' => ['acme.test'],
    ]);

    expect((new EnterpriseConnectionService)->findByIdentifierDomain($env, 'alice@acme.test'))->not->toBeNull();
});

it('skips unverified OrganizationDomain rows when routing', function (): void {
    $env = makeEnvForEnrollment();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    OrganizationDomain::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'name' => 'acme.test',
        'verified' => false,
        'enrollment_mode' => OrganizationDomain::MODE_AUTOMATIC_INVITATION,
    ]);
    $orgConn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'domains' => ['acme.test'],
    ]);
    $instanceConn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => null,
        'domains' => ['acme.test'],
    ]);

    // Unverified org domain → routing falls back to instance-wide connection.
    $found = (new EnterpriseConnectionService)->findByIdentifierDomain($env, 'alice@acme.test');
    expect($found?->id)->toBe($instanceConn->id);
    expect($found?->id)->not->toBe($orgConn->id);
});

it('confirms Role.key exists for the test default role (sanity for enrollment auto-join logic)', function (): void {
    $env = makeEnvForEnrollment();
    $row = Role::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('key', 'org:member')
        ->first();

    expect($row)->not->toBeNull();
    // Ensure OrganizationMembership rows can be created against this role
    // — the SSO callback's autojoinOrgIfApplicable relies on this lookup.
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'O', 'slug' => 'o']);
    $user = User::create(['environment_id' => $env->id]);
    $m = OrganizationMembership::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $row->id,
    ]);
    expect($m->id)->toStartWith('orgmem_');
});
