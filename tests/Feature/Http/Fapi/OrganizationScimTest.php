<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ScimAttributeMapping;
use App\Models\ScimToken;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

function fapiOrgScimReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

function fapiScimMakeOrg(Environment $env, User $user, string $roleKey = 'org:admin'): Organization
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

    $r = fapiOrgScimReq('GET', "/organizations/{$org->id}/scim/tokens", $auth['jwt']);
    $r->assertStatus(404)->assertJsonPath('errors.0.code', 'organization_not_found');
});

it('rejects members lacking org:sys_provisioning:manage with 403', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiScimMakeOrg($f['env'], $auth['user'], 'org:member');

    $r = fapiOrgScimReq('GET', "/organizations/{$org->id}/scim/tokens", $auth['jwt']);
    $r->assertStatus(403)->assertJsonPath('errors.0.code', 'authorization_invalid');
});

it('issues a SCIM token and returns the plaintext exactly once', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiScimMakeOrg($f['env'], $auth['user']);

    $r = fapiOrgScimReq('POST', "/organizations/{$org->id}/scim/tokens", $auth['jwt'], [
        'name' => 'Okta sync',
    ]);

    $r->assertCreated()
        ->assertJsonPath('object', 'scim_token')
        ->assertJsonPath('name', 'Okta sync')
        ->assertJsonPath('organization_id', $org->id);
    expect($r->json('token'))->toStartWith('scim_');
    expect($r->json('prefix'))->toStartWith('scim_');

    // The list path never re-exposes the plaintext.
    $listed = fapiOrgScimReq('GET', "/organizations/{$org->id}/scim/tokens", $auth['jwt']);
    expect($listed->json('data.0'))->not->toHaveKey('token');
    expect($listed->json('data.0'))->not->toHaveKey('hashed_token');
});

it('revokes a SCIM token stamping revoked_at', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiScimMakeOrg($f['env'], $auth['user']);
    $minted = ScimToken::mintPlaintext();
    $token = ScimToken::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'hashed_token' => $minted['hash'],
        'prefix' => $minted['prefix'],
        'name' => 't',
        'created_by_user_id' => $auth['user']->id,
    ]);

    $r = fapiOrgScimReq('POST', "/organizations/{$org->id}/scim/tokens/{$token->id}/revoke", $auth['jwt']);

    $r->assertOk();
    expect($r->json('revoked_at'))->not->toBeNull();
});

it('shows + replaces the per-org SCIM attribute mappings (round-trip)', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiScimMakeOrg($f['env'], $auth['user']);

    // Initial GET returns the defaults rolled into the `mapping` blob.
    $initial = fapiOrgScimReq('GET', "/organizations/{$org->id}/scim/attribute-mappings", $auth['jwt']);
    $initial->assertOk()
        ->assertJsonPath('organization_id', $org->id);
    expect($initial->json('mapping.userName'))->toBe('email_address'); // baked default

    // PUT replaces with a custom override.
    $replaced = fapiOrgScimReq('PUT', "/organizations/{$org->id}/scim/attribute-mappings", $auth['jwt'], [
        'mapping' => [
            'userName' => 'username',
            'custom.dept' => 'public_metadata.department',
        ],
    ]);
    $replaced->assertOk()
        ->assertJsonPath('mapping.userName', 'username');
    expect($replaced->json('mapping'))->toHaveKey('custom.dept');
    expect($replaced->json('mapping')['custom.dept'])->toBe('public_metadata.department');

    // Subsequent GET reflects the new override set.
    $after = fapiOrgScimReq('GET', "/organizations/{$org->id}/scim/attribute-mappings", $auth['jwt']);
    expect($after->json('mapping.userName'))->toBe('username');

    expect(ScimAttributeMapping::query()->withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(2);
});

it('exposes the SCIM endpoint URL for IdP-side handoff', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = fapiScimMakeOrg($f['env'], $auth['user']);

    $r = fapiOrgScimReq('GET', "/organizations/{$org->id}/scim/endpoint", $auth['jwt']);
    $r->assertOk()
        ->assertJsonPath('endpoint_url', 'https://acme.authn.local/scim/v2')
        ->assertJsonPath('users_url', 'https://acme.authn.local/scim/v2/Users');
});
