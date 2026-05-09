<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationInvitationCreated;
use App\Events\Organizations\OrganizationInvitationRevoked;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Http\Bapi\BapiTestSupport;
use Tests\TestCase;

/**
 * @return array{env: Environment, headers: array, org_id: string}
 */
function setupOrgForInvitations(TestCase $testCase): array
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

it('POST /invitations creates a pending invitation, fires the event, returns a ticket url', function (): void {
    Event::fake([OrganizationInvitationCreated::class]);
    $ctx = setupOrgForInvitations($this);

    $r = $this->withHeaders($ctx['headers'])->postJson(
        BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations"),
        [
            'email_address' => 'invitee@example.com',
            'role' => 'org:member',
            'redirect_url' => 'https://app.example.com/welcome',
            'public_metadata' => ['team' => 'engineering'],
        ],
    );

    $r->assertStatus(201)
        ->assertJsonPath('object', 'organization_invitation')
        ->assertJsonPath('email_address', 'invitee@example.com')
        ->assertJsonPath('role', 'org:member')
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('public_metadata.team', 'engineering');
    expect($r->json('id'))->toStartWith('orginv_');
    expect($r->json('url'))->toContain('__authn_ticket=')->toContain('__authn_status=sign_up');

    $org = Organization::query()->withoutGlobalScopes()->where('id', $ctx['org_id'])->firstOrFail();
    expect($org->pending_invitations_count)->toBe(1);

    Event::assertDispatched(OrganizationInvitationCreated::class, 1);
});

it('POST /invitations 422s on unknown role key', function (): void {
    $ctx = setupOrgForInvitations($this);
    $this->withHeaders($ctx['headers'])->postJson(
        BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations"),
        ['email_address' => 'a@example.com', 'role' => 'org:nonsense'],
    )->assertStatus(422);
});

it('POST /invitations 409s on duplicate active invitation for the same email', function (): void {
    $ctx = setupOrgForInvitations($this);
    $body = ['email_address' => 'dup@example.com', 'role' => 'org:member'];

    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations"), $body)->assertStatus(201);
    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations"), $body)->assertStatus(409);
});

it('GET /invitations supports status + query filters', function (): void {
    $ctx = setupOrgForInvitations($this);
    foreach (['alpha@example.com', 'beta@example.com'] as $email) {
        $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations"), [
            'email_address' => $email,
            'role' => 'org:member',
        ])->assertStatus(201);
    }

    $r = $this->withHeaders($ctx['headers'])->getJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations?status=pending&query=alpha"));
    $r->assertOk()->assertJsonPath('total_count', 1);
});

it('POST /invitations/{id}/revoke flips status, fires the event, idempotent on re-revoke', function (): void {
    Event::fake([OrganizationInvitationRevoked::class]);
    $ctx = setupOrgForInvitations($this);
    $created = $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations"), [
        'email_address' => 'revoke-me@example.com',
        'role' => 'org:member',
    ]);
    $id = $created->json('id');

    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations/{$id}/revoke"))
        ->assertOk()->assertJsonPath('status', 'revoked');

    $org = Organization::query()->withoutGlobalScopes()->where('id', $ctx['org_id'])->firstOrFail();
    expect($org->pending_invitations_count)->toBe(0);

    // Re-revoke is a no-op (still 200).
    $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations/{$id}/revoke"))
        ->assertOk()->assertJsonPath('status', 'revoked');

    Event::assertDispatched(OrganizationInvitationRevoked::class, 1);
});

it('POST /invitations/bulk creates all rows atomically', function (): void {
    Event::fake([OrganizationInvitationCreated::class]);
    $ctx = setupOrgForInvitations($this);

    $r = $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations/bulk"), [
        'invitations' => [
            ['email_address' => 'one@example.com', 'role' => 'org:member'],
            ['email_address' => 'two@example.com', 'role' => 'org:admin'],
            ['email_address' => 'three@example.com', 'role' => 'org:member'],
        ],
    ]);
    $r->assertStatus(201)
        ->assertJsonPath('total', 3)
        ->assertJsonPath('created', 3);

    $org = Organization::query()->withoutGlobalScopes()->where('id', $ctx['org_id'])->firstOrFail();
    expect($org->pending_invitations_count)->toBe(3);
    Event::assertDispatched(OrganizationInvitationCreated::class, 3);
});

it('POST /invitations/bulk rolls everything back when one row fails', function (): void {
    Event::fake([OrganizationInvitationCreated::class]);
    $ctx = setupOrgForInvitations($this);

    $r = $this->withHeaders($ctx['headers'])->postJson(BapiTestSupport::url("/organizations/{$ctx['org_id']}/invitations/bulk"), [
        'invitations' => [
            ['email_address' => 'good@example.com', 'role' => 'org:member'],
            ['email_address' => 'bad@example.com', 'role' => 'org:nonsense'], // unknown role key
        ],
    ]);
    $r->assertStatus(422);

    expect(OrganizationInvitation::query()->where('organization_id', $ctx['org_id'])->count())->toBe(0);
    Event::assertNotDispatched(OrganizationInvitationCreated::class);
});

it('POST /invitations 401s without a key', function (): void {
    BapiTestSupport::bootEnv();
    $this->withHeaders(['Host' => 'api.authn.local', 'Accept' => 'application/json'])
        ->postJson(BapiTestSupport::url('/organizations/org_doesnotexist/invitations'), ['email_address' => 'a@example.com', 'role' => 'org:member'])
        ->assertStatus(401);
});

it('POST /invitations 404s for an org that lives in a different env', function (): void {
    $a = BapiTestSupport::bootEnv('aaa');
    $r = $this->withHeaders(BapiTestSupport::headers($a['token']))
        ->postJson(BapiTestSupport::url('/organizations'), ['name' => 'Only A']);
    $orgId = $r->json('id');

    $b = BapiTestSupport::bootEnv('bbb');
    $this->withHeaders(BapiTestSupport::headers($b['token']))
        ->postJson(BapiTestSupport::url("/organizations/{$orgId}/invitations"), ['email_address' => 'x@example.com', 'role' => 'org:member'])
        ->assertStatus(404);
});
