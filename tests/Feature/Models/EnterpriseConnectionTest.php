<?php

declare(strict_types=1);

use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Database\QueryException;

function makeEnvForEnterprise(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints an entcon_ prefixed id and casts JSON columns to arrays', function (): void {
    $env = makeEnvForEnterprise();

    $conn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'domains' => ['acme.test', 'corp.acme.test'],
        'attribute_mapping' => ['email' => 'email_address'],
    ]);

    expect($conn->id)->toStartWith('entcon_');
    expect($conn->domains)->toBe(['acme.test', 'corp.acme.test']);
    expect($conn->attribute_mapping)->toBe(['email' => 'email_address']);
    expect($conn->enabled)->toBeTrue();
    expect($conn->isSaml())->toBeTrue();
    expect($conn->isOidc())->toBeFalse();
});

it('encrypts saml_signing_key + oidc_client_secret and hides them from arrays', function (): void {
    $env = makeEnvForEnterprise();

    $conn = EnterpriseConnection::factory()->oidc()->create([
        'environment_id' => $env->id,
        'oidc_client_secret' => 'super-secret-oidc',
        'saml_signing_key' => "-----BEGIN PRIVATE KEY-----\nMIIBfake\n-----END PRIVATE KEY-----",
    ]);

    expect($conn->oidc_client_secret)->toBe('super-secret-oidc');
    expect($conn->saml_signing_key)->toContain('BEGIN PRIVATE KEY');

    $rawOidc = (string) DB::table('enterprise_connections')->where('id', $conn->id)->value('oidc_client_secret');
    expect($rawOidc)->not->toBe('super-secret-oidc');
    expect(strlen($rawOidc))->toBeGreaterThan(20);

    $arr = $conn->toArray();
    expect(array_key_exists('oidc_client_secret', $arr))->toBeFalse();
    expect(array_key_exists('saml_signing_key', $arr))->toBeFalse();
});

it('belongs to an Organization when org-scoped, null when instance-wide', function (): void {
    $env = makeEnvForEnterprise();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);

    $orgConn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
    ]);
    $instanceConn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => null,
    ]);

    expect($orgConn->organization?->id)->toBe($org->id);
    expect($instanceConn->organization)->toBeNull();
    expect(EnterpriseConnection::query()->instanceWide()->pluck('id')->all())->toBe([$instanceConn->id]);
    expect($org->refresh()->enterpriseConnections->pluck('id')->all())->toBe([$orgConn->id]);
});

it('cascades enterprise_connections deletion when the parent organization is deleted', function (): void {
    $env = makeEnvForEnterprise();
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    $conn = EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
    ]);

    DB::table('organizations')->where('id', $org->id)->delete();

    expect(DB::table('enterprise_connections')->where('id', $conn->id)->exists())->toBeFalse();
});

it('soft-deletes via removed_at and the enabled() scope filters disabled rows', function (): void {
    $env = makeEnvForEnterprise();
    $enabled = EnterpriseConnection::factory()->create(['environment_id' => $env->id]);
    EnterpriseConnection::factory()->disabled()->create(['environment_id' => $env->id]);
    $removed = EnterpriseConnection::factory()->create(['environment_id' => $env->id]);
    $removed->delete();

    expect(EnterpriseConnection::query()->enabled()->pluck('id')->all())->toBe([$enabled->id]);
    expect(EnterpriseConnection::withTrashed()->find($removed->id)?->removed_at)->not->toBeNull();
});

it('mints an entacc_ prefixed id, encrypts id_token, and exposes user + connection relations', function (): void {
    $env = makeEnvForEnterprise();
    $user = User::create(['environment_id' => $env->id]);
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $env->id]);

    $account = EnterpriseAccount::factory()->create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $conn->id,
        'id_token' => 'cleartext-id-token',
    ]);

    expect($account->id)->toStartWith('entacc_');
    expect($account->id_token)->toBe('cleartext-id-token');
    expect($account->enterpriseConnection->id)->toBe($conn->id);
    expect($account->user->id)->toBe($user->id);
    expect($user->refresh()->enterpriseAccounts->pluck('id')->all())->toBe([$account->id]);
    expect(array_key_exists('id_token', $account->toArray()))->toBeFalse();

    $raw = (string) DB::table('enterprise_accounts')->where('id', $account->id)->value('id_token');
    expect($raw)->not->toBe('cleartext-id-token');
});

it('enforces unique (enterprise_connection_id, provider_user_id) on EnterpriseAccount', function (): void {
    $env = makeEnvForEnterprise();
    $user = User::create(['environment_id' => $env->id]);
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $env->id]);

    EnterpriseAccount::factory()->create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $conn->id,
        'provider_user_id' => 'subject-1',
    ]);

    expect(fn () => EnterpriseAccount::factory()->create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $conn->id,
        'provider_user_id' => 'subject-1',
    ]))->toThrow(QueryException::class);
});

it('cascades enterprise_accounts when the parent connection is deleted hard', function (): void {
    $env = makeEnvForEnterprise();
    $user = User::create(['environment_id' => $env->id]);
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $env->id]);
    $account = EnterpriseAccount::factory()->create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $conn->id,
    ]);

    DB::table('enterprise_connections')->where('id', $conn->id)->delete();

    expect(DB::table('enterprise_accounts')->where('id', $account->id)->exists())->toBeFalse();
});

it('extends Verification::strategies() with enterprise_sso and saml', function (): void {
    expect(Verification::strategies())->toContain(Verification::STRATEGY_ENTERPRISE_SSO);
    expect(Verification::strategies())->toContain(Verification::STRATEGY_SAML);
    expect(Verification::isValidStrategy('enterprise_sso'))->toBeTrue();
    expect(Verification::isValidStrategy('saml'))->toBeTrue();
});
