<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationDomainCreated;
use App\Events\Organizations\OrganizationDomainDeleted;
use App\Events\Organizations\OrganizationDomainUpdated;
use App\Jobs\Organizations\VerifyOrganizationDomain;
use App\Models\Environment;
use App\Models\OrganizationDomain;
use App\Models\Verification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Http\Bapi\BapiTestSupport;
use Tests\TestCase;

/**
 * @return array{env: Environment, headers: array, org_id: string}
 */
function setupOrgForDomains(TestCase $testCase): array
{
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);
    $r = $testCase->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'Acme']);

    return [
        'env' => $f['env'],
        'headers' => $headers,
        'org_id' => $r->json('id'),
    ];
}

it('POST /domains creates a domain, mints a domain_dns_txt Verification, fires the event', function (): void {
    Event::fake([OrganizationDomainCreated::class]);
    $ctx = setupOrgForDomains($this);

    $r = $this->withHeaders($ctx['headers'])->postJson(
        BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"),
        ['name' => 'acme.test', 'enrollment_mode' => 'automatic_invitation', 'affiliation_email_address' => 'verify@acme.test'],
    );

    $r->assertStatus(201)
        ->assertJsonPath('object', 'organization_domain')
        ->assertJsonPath('name', 'acme.test')
        ->assertJsonPath('enrollment_mode', 'automatic_invitation')
        ->assertJsonPath('verified', false)
        ->assertJsonPath('verification.strategy', 'domain_dns_txt')
        ->assertJsonPath('verification.status', 'unverified');
    expect($r->json('id'))->toStartWith('orgdom_');
    expect($r->json('verification.nonce'))->toStartWith('authn-domain-verify=');

    $verificationId = $r->json('verification.id');
    expect(Verification::query()->withoutGlobalScopes()->where('id', $verificationId)->exists())->toBeTrue();

    Event::assertDispatched(OrganizationDomainCreated::class, 1);
});

it('POST /domains 409s on a duplicate domain name in the same env', function (): void {
    $ctx = setupOrgForDomains($this);
    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"), ['name' => 'dup.test'])->assertStatus(201);
    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"), ['name' => 'dup.test'])->assertStatus(409);
});

it('POST /domains 422s on a malformed name', function (): void {
    $ctx = setupOrgForDomains($this);
    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"), ['name' => 'not a domain'])->assertStatus(422);
});

it('GET /domains lists rows scoped to the org', function (): void {
    $ctx = setupOrgForDomains($this);
    foreach (['a.test', 'b.test'] as $name) {
        $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"), ['name' => $name])->assertStatus(201);
    }
    $r = $this->withHeaders($ctx['headers'])->getJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"));
    $r->assertOk()->assertJsonPath('total_count', 2);
});

it('GET /domains/{id} returns the verification block', function (): void {
    $ctx = setupOrgForDomains($this);
    $created = $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"), ['name' => 'show.test']);
    $id = $created->json('id');

    $r = $this->withHeaders($ctx['headers'])->getJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains/{$id}"));
    $r->assertOk()->assertJsonPath('verification.strategy', 'domain_dns_txt');
});

it('PATCH /domains/{id} updates enrollment_mode and fires the event', function (): void {
    Event::fake([OrganizationDomainUpdated::class]);
    $ctx = setupOrgForDomains($this);
    $created = $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"), ['name' => 'patch.test']);
    $id = $created->json('id');

    $r = $this->withHeaders($ctx['headers'])->patchJson(
        BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains/{$id}"),
        ['enrollment_mode' => 'automatic_suggestion'],
    );
    $r->assertOk()->assertJsonPath('enrollment_mode', 'automatic_suggestion');
    Event::assertDispatched(OrganizationDomainUpdated::class, 1);
});

it('POST /domains/{id}/verify re-issues a fresh Verification with a new nonce', function (): void {
    Bus::fake([VerifyOrganizationDomain::class]);
    $ctx = setupOrgForDomains($this);
    $created = $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"), ['name' => 'verify.test']);
    $id = $created->json('id');
    $firstVerificationId = $created->json('verification.id');
    $firstNonce = $created->json('verification.nonce');

    $r = $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains/{$id}/verify"));
    $r->assertOk();
    expect($r->json('verification.id'))->not->toBe($firstVerificationId);
    expect($r->json('verification.nonce'))->not->toBe($firstNonce);
    expect($r->json('verification.strategy'))->toBe('domain_dns_txt');
    Bus::assertDispatched(VerifyOrganizationDomain::class, 1);
});

it('DELETE /domains/{id} removes the row and fires the event', function (): void {
    Event::fake([OrganizationDomainDeleted::class]);
    $ctx = setupOrgForDomains($this);
    $created = $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains"), ['name' => 'delete.test']);
    $id = $created->json('id');

    $r = $this->withHeaders($ctx['headers'])->deleteJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/domains/{$id}"));
    $r->assertOk()->assertJsonPath('deleted', true);
    expect(OrganizationDomain::query()->withoutGlobalScopes()->where('id', $id)->exists())->toBeFalse();
    Event::assertDispatched(OrganizationDomainDeleted::class, 1);
});

it('GET /domains/{id} 404s across env boundaries', function (): void {
    $a = BapiTestSupport::bootEnv('aaa');
    $r = $this->withHeaders(BapiTestSupport::headers($a['token']))
        ->postJson(BapiTestSupport::url('/organizations'), ['name' => 'Only A']);
    $orgId = $r->json('id');
    $created = $this->withHeaders(BapiTestSupport::headers($a['token']))
        ->postJson(BapiTestSupport::url("/organizations/{$orgId}/domains"), ['name' => 'only-a.test']);
    $domainId = $created->json('id');

    $b = BapiTestSupport::bootEnv('bbb');
    $this->withHeaders(BapiTestSupport::headers($b['token']))
        ->getJson(BapiTestSupport::url("/organizations/{$orgId}/domains/{$domainId}"))->assertStatus(404);
});
