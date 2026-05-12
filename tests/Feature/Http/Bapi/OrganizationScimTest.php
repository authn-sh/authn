<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\ScimAttributeMapping;
use App\Models\User;
use Tests\Feature\Http\Bapi\BapiTestSupport;

function makeOrgForScimBapi(string $envSlug = 'acme'): array
{
    $f = BapiTestSupport::bootEnv($envSlug);
    $org = Organization::create([
        'environment_id' => $f['env']->id,
        'name' => 'Acme Co',
        'slug' => 'acme-co',
    ]);
    $user = User::create(['environment_id' => $f['env']->id]);

    return ['env' => $f['env'], 'token' => $f['token'], 'org' => $org, 'user' => $user];
}

it('lists, issues, and revokes SCIM tokens for an org via BAPI', function (): void {
    $f = makeOrgForScimBapi();

    // List empty.
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/organizations/'.$f['org']->id.'/scim/tokens'));
    $r->assertOk()->assertJsonPath('total_count', 0);

    // Issue.
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/organizations/'.$f['org']->id.'/scim/tokens'), [
            'name' => 'CI sync',
        ]);
    $r->assertCreated()
        ->assertJsonPath('object', 'scim_token')
        ->assertJsonPath('organization_id', $f['org']->id)
        ->assertJsonPath('name', 'CI sync');
    $plaintext = $r->json('token');
    $tokenId = $r->json('id');
    expect($plaintext)->toStartWith('scim_');
    expect($tokenId)->toStartWith('scimt_');

    // List shows the issued row but never re-leaks the plaintext.
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/organizations/'.$f['org']->id.'/scim/tokens'));
    $r->assertOk()->assertJsonPath('total_count', 1);
    expect($r->json('data.0.token'))->toBeNull();

    // Revoke.
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/organizations/'.$f['org']->id.'/scim/tokens/'.$tokenId.'/revoke'));
    $r->assertOk()->assertJsonPath('id', $tokenId);
    expect($r->json('revoked_at'))->not->toBeNull();
});

it('refuses access without bearer credentials', function (): void {
    $f = makeOrgForScimBapi();

    $r = $this->getJson(BapiTestSupport::url('/organizations/'.$f['org']->id.'/scim/tokens'), [
        'Host' => 'api.authn.local',
    ]);
    $r->assertStatus(401);
});

it('returns 404 for an org outside the env', function (): void {
    $f = makeOrgForScimBapi();
    $other = makeOrgForScimBapi('beta');

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/organizations/'.$other['org']->id.'/scim/tokens'));
    $r->assertStatus(404)->assertJsonPath('errors.0.code', 'organization_not_found');
});

it('refuses issuance in an env that has no users to attribute the token to', function (): void {
    $f = BapiTestSupport::bootEnv('empty');
    $org = Organization::create([
        'environment_id' => $f['env']->id,
        'name' => 'Empty Co',
        'slug' => 'empty-co',
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/organizations/'.$org->id.'/scim/tokens'), [
            'name' => 'attempt',
        ]);
    $r->assertStatus(422)->assertJsonPath('errors.0.code', 'no_user_to_attribute');
});

it('reads + replaces attribute mappings', function (): void {
    $f = makeOrgForScimBapi();

    // Defaults exposed by GET.
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/organizations/'.$f['org']->id.'/scim/attribute-mappings'));
    $r->assertOk()
        ->assertJsonPath('organization_id', $f['org']->id)
        ->assertJsonStructure(['mapping']);

    // PUT replaces atomically.
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->putJson(BapiTestSupport::url('/organizations/'.$f['org']->id.'/scim/attribute-mappings'), [
            'mapping' => [
                'userName' => 'email_address',
                'givenName' => 'first_name',
            ],
        ]);
    $r->assertOk()
        ->assertJsonPath('mapping.userName', 'email_address')
        ->assertJsonPath('mapping.givenName', 'first_name');

    $count = ScimAttributeMapping::query()->withoutGlobalScopes()
        ->where('organization_id', $f['org']->id)
        ->count();
    expect($count)->toBe(2);

    // PUT again with a smaller set drops the previously-persisted overrides.
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->putJson(BapiTestSupport::url('/organizations/'.$f['org']->id.'/scim/attribute-mappings'), [
            'mapping' => ['userName' => 'email_address'],
        ]);
    $r->assertOk();
    $count = ScimAttributeMapping::query()->withoutGlobalScopes()
        ->where('organization_id', $f['org']->id)
        ->count();
    expect($count)->toBe(1);
});

it('exposes the SCIM endpoint URL for the org', function (): void {
    $f = makeOrgForScimBapi();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/organizations/'.$f['org']->id.'/scim/endpoint'));
    $r->assertOk()
        ->assertJsonStructure(['endpoint_url']);
    expect($r->json('endpoint_url'))->toEndWith('/scim/v2/');
});
