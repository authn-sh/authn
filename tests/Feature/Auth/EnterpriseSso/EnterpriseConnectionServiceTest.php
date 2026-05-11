<?php

declare(strict_types=1);

use App\Auth\EnterpriseSso\EnterpriseConnectionService;
use App\Models\EmailAddress;
use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\Project;
use App\Models\User;

function makeEntServiceEnv(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('returns null when the identifier has no domain or no connection matches', function (): void {
    $env = makeEntServiceEnv();
    $service = new EnterpriseConnectionService;

    expect($service->findByIdentifierDomain($env, 'no-at-sign'))->toBeNull();
    expect($service->findByIdentifierDomain($env, 'alice@unknown.test'))->toBeNull();
});

it('prefers an org-scoped connection when the identifier domain matches a verified org domain', function (): void {
    $env = makeEntServiceEnv();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    OrganizationDomain::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'name' => 'acme.test',
        'verified' => true,
        'enrollment_mode' => 'manual_invitation',
    ]);

    $instanceConn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => null,
        'domains' => ['acme.test'],
    ]);
    $orgConn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'domains' => ['acme.test'],
    ]);

    $found = (new EnterpriseConnectionService)->findByIdentifierDomain($env, 'alice@acme.test');

    expect($found?->id)->toBe($orgConn->id);
    expect($found?->id)->not->toBe($instanceConn->id);
});

it('falls back to an instance-wide connection when no org-scoped match covers the domain', function (): void {
    $env = makeEntServiceEnv();
    $conn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => null,
        'domains' => ['acme.test', 'corp.acme.test'],
    ]);

    expect((new EnterpriseConnectionService)->findByIdentifierDomain($env, 'alice@acme.test')?->id)->toBe($conn->id);
    expect((new EnterpriseConnectionService)->findByIdentifierDomain($env, 'bob@CORP.ACME.TEST')?->id)->toBe($conn->id);
});

it('skips disabled connections even when the domain matches', function (): void {
    $env = makeEntServiceEnv();
    EnterpriseConnection::factory()->disabled()->create([
        'environment_id' => $env->id,
        'domains' => ['acme.test'],
    ]);

    expect((new EnterpriseConnectionService)->findByIdentifierDomain($env, 'alice@acme.test'))->toBeNull();
});

it('creates a User + EnterpriseAccount when the identity is brand new', function (): void {
    $env = makeEntServiceEnv();
    $conn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'attribute_mapping' => ['department' => 'department'],
    ]);

    $result = (new EnterpriseConnectionService)->provisionUserFromIdentity($conn, [
        'provider_user_id' => 'idp-subject-1',
        'email_address' => 'alice@acme.test',
        'first_name' => 'Alice',
        'last_name' => 'Smith',
        'id_token' => 'id.tok.value',
        'raw_attributes' => ['department' => 'Engineering', 'extra' => 'dropped'],
    ]);

    expect($result['was_created'])->toBeTrue();
    expect($result['user']->id)->toStartWith('user_');
    expect($result['user']->first_name)->toBe('Alice');
    expect($result['account']->id)->toStartWith('entacc_');
    expect($result['account']->provider_user_id)->toBe('idp-subject-1');
    expect($result['account']->id_token)->toBe('id.tok.value');
    expect($result['account']->public_metadata)->toBe(['department' => 'Engineering']);

    $email = EmailAddress::query()->withoutGlobalScopes()->where('user_id', $result['user']->id)->first();
    expect($email?->email_address)->toBe('alice@acme.test');
    expect($email?->isVerified())->toBeTrue();
    expect($email?->is_primary)->toBeTrue();
});

it('reuses an existing User when the email already exists and links a new EnterpriseAccount', function (): void {
    $env = makeEntServiceEnv();
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $env->id]);

    $existing = User::create(['environment_id' => $env->id]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'user_id' => $existing->id,
        'email_address' => 'alice@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $result = (new EnterpriseConnectionService)->provisionUserFromIdentity($conn, [
        'provider_user_id' => 'idp-subject-1',
        'email_address' => 'alice@acme.test',
        'first_name' => null,
        'last_name' => null,
        'id_token' => null,
        'raw_attributes' => [],
    ]);

    expect($result['was_created'])->toBeFalse();
    expect($result['user']->id)->toBe($existing->id);
    expect($result['account']->user_id)->toBe($existing->id);
});

it('reuses the same EnterpriseAccount when the provider subject is already linked', function (): void {
    $env = makeEntServiceEnv();
    $user = User::create(['environment_id' => $env->id]);
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $env->id]);

    EnterpriseAccount::factory()->create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $conn->id,
        'provider_user_id' => 'idp-subject-1',
        'id_token' => 'old.tok',
    ]);

    $result = (new EnterpriseConnectionService)->provisionUserFromIdentity($conn, [
        'provider_user_id' => 'idp-subject-1',
        'email_address' => null,
        'first_name' => null,
        'last_name' => null,
        'id_token' => 'new.tok',
        'raw_attributes' => [],
    ]);

    expect($result['was_created'])->toBeFalse();
    expect($result['user']->id)->toBe($user->id);
    expect($result['account']->id_token)->toBe('new.tok');
});
