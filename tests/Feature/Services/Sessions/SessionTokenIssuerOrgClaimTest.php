<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Session;
use App\Services\Sessions\SessionTokenIssuer;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Tests\Feature\Http\Me\MeTestSupport;

function decodeJwtClaims(string $jwt): array
{
    $config = Configuration::forSymmetricSigner(
        new Sha256,
        InMemory::plainText(str_repeat('x', 64)),
    );
    $token = $config->parser()->parse($jwt);

    return $token->claims()->all();
}

it('omits the org claim when Session has no active organization', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeJwtClaims($minted['jwt']);

    expect($claims)->not->toHaveKey('org');
});

it('embeds the org claim with id, slg, rol, per when active org is set', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
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
    $auth['session']->forceFill(['last_active_organization_id' => $org->id])->save();

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeJwtClaims($minted['jwt']);

    expect($claims)->toHaveKey('org');
    $orgClaim = $claims['org'];
    expect($orgClaim['id'])->toBe($org->id);
    expect($orgClaim['slg'])->toBe('acme');
    expect($orgClaim['rol'])->toBe('org:admin');
    // Admin has every system permission (13 from AU-2).
    expect($orgClaim['per'])->toContain(
        'org:sys_profile:manage',
        'org:sys_memberships:manage',
        'org:sys_billing:manage',
    );
    expect(count($orgClaim['per']))->toBe(13);
});

it('reflects role permission changes on the next mint', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    $member = Role::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('key', 'org:member')
        ->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'user_id' => $auth['user']->id,
        'role_id' => $member->id,
    ]);
    $auth['session']->forceFill(['last_active_organization_id' => $org->id])->save();

    $first = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $firstClaims = decodeJwtClaims($first['jwt']);
    expect($firstClaims['org']['per'])->not->toContain('org:sys_memberships:manage');

    // Operator grants the member role the manage permission.
    $managePerm = Permission::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('key', 'org:sys_memberships:manage')
        ->firstOrFail();
    $member->permissions()->attach($managePerm->id);

    $second = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $secondClaims = decodeJwtClaims($second['jwt']);
    expect($secondClaims['org']['per'])->toContain('org:sys_memberships:manage');
});

it('omits the org claim when the user has no membership in the active org', function (): void {
    // Edge case: session.last_active_organization_id was set, but the
    // membership was deleted. The mint should defensively omit `org` instead
    // of throwing or surfacing stale data.
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $auth['session']->forceFill(['last_active_organization_id' => 'org_'.str_repeat('A', 26)])->save();

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeJwtClaims($minted['jwt']);

    expect($claims)->not->toHaveKey('org');
});
