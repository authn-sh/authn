<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Session;
use App\Models\User;
use App\Services\Client\ClientResolver;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

function activeOrgFapiReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

function setupSessionWithMembership(): array
{
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme-touch']);
    $admin = Role::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('key', 'org:admin')
        ->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'user_id' => $auth['user']->id,
        'role_id' => $admin->id,
    ]);

    return ['env' => $f['env'], 'auth' => $auth, 'org' => $org];
}

it('PUT /me/active-organization bumps Session.token_version on change', function (): void {
    $ctx = setupSessionWithMembership();
    $session = $ctx['auth']['session'];
    $beforeVersion = (int) $session->fresh()->token_version;

    activeOrgFapiReq('PUT', '/me/active-organization', $ctx['auth']['jwt'], [
        'organization_id' => $ctx['org']->id,
    ])->assertOk();

    $after = Session::query()->withoutGlobalScopes()->where('id', $session->id)->firstOrFail();
    expect($after->token_version)->toBe($beforeVersion + 1);
    expect($after->last_active_organization_id)->toBe($ctx['org']->id);
});

it('PUT /me/active-organization is a no-op when the org is already active', function (): void {
    $ctx = setupSessionWithMembership();
    $session = $ctx['auth']['session'];
    $session->forceFill(['last_active_organization_id' => $ctx['org']->id, 'token_version' => 7])->save();

    activeOrgFapiReq('PUT', '/me/active-organization', $ctx['auth']['jwt'], [
        'organization_id' => $ctx['org']->id,
    ])->assertOk();

    $after = Session::query()->withoutGlobalScopes()->where('id', $session->id)->firstOrFail();
    expect($after->token_version)->toBe(7);
});

it('POST /client/sessions/{sid}/touch with active_organization_id validates membership', function (): void {
    $ctx = setupSessionWithMembership();

    // Org the user is NOT in.
    $other = new User(['environment_id' => $ctx['env']->id, 'username' => 'other']);
    $other->save();
    $otherOrg = Organization::create(['environment_id' => $ctx['env']->id, 'name' => 'Foreign', 'slug' => 'foreign']);
    $admin = Role::query()->withoutGlobalScopes()
        ->where('environment_id', $ctx['env']->id)
        ->where('key', 'org:admin')
        ->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $ctx['env']->id,
        'organization_id' => $otherOrg->id,
        'user_id' => $other->id,
        'role_id' => $admin->id,
    ]);

    $cookie = app(ClientResolver::class)->mintCookieValue($ctx['auth']['client']);

    test()->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
        ])
        ->json('POST', "https://acme.authn.local/v1/client/sessions/{$ctx['auth']['session']->id}/touch", [
            'active_organization_id' => $otherOrg->id,
        ])->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'organization_not_a_member');
});

it('POST /client/sessions/{sid}/touch with valid active_organization_id bumps token_version', function (): void {
    $ctx = setupSessionWithMembership();
    $beforeVersion = (int) $ctx['auth']['session']->fresh()->token_version;

    $cookie = app(ClientResolver::class)->mintCookieValue($ctx['auth']['client']);

    test()->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
        ])
        ->json('POST', "https://acme.authn.local/v1/client/sessions/{$ctx['auth']['session']->id}/touch", [
            'active_organization_id' => $ctx['org']->id,
        ])->assertOk();

    $after = Session::query()->withoutGlobalScopes()->where('id', $ctx['auth']['session']->id)->firstOrFail();
    expect($after->last_active_organization_id)->toBe($ctx['org']->id);
    expect($after->token_version)->toBe($beforeVersion + 1);
});
