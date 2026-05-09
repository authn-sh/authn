<?php

declare(strict_types=1);

use App\Jobs\Mail\SendInvitationEmail;
use App\Models\Invitation;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Http\Bapi\BapiTestSupport;


it('creates an invitation, dispatches the email job, exposes the ticket url, and revokes', function (): void {
    Bus::fake();
    $f = BapiTestSupport::bootEnv();

    $created = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/invitations'), [
            'email_address' => 'invited@example.com',
            'redirect_url' => 'https://app.example.com/welcome',
            'public_metadata' => ['team_id' => 'team_42'],
        ]);
    $created->assertStatus(201)
        ->assertJsonPath('email_address', 'invited@example.com')
        ->assertJsonPath('public_metadata.team_id', 'team_42');
    expect($created->json('url'))->toContain('__authn_ticket=');

    Bus::assertDispatched(SendInvitationEmail::class, fn ($job) => $job->emailAddress === 'invited@example.com');

    $id = $created->json('id');
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/invitations/{$id}/revoke"))
        ->assertOk()->assertJsonPath('status', 'revoked');
    expect(Invitation::query()->withoutGlobalScopes()->where('id', $id)->first()->status)->toBe('revoked');
});

it('bulk endpoint returns per-row results, including failures', function (): void {
    Bus::fake();
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/invitations/bulk'), [
            'invitations' => [
                ['email_address' => 'one@example.com'],
                ['email_address' => 'two@example.com'],
                ['email_address' => 'three@example.com'],
            ],
        ]);
    $r->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('created', 3)
        ->assertJsonCount(3, 'results');
    Bus::assertDispatchedTimes(SendInvitationEmail::class, 3);
});

it('rejects bulk with > 100 entries via the FormRequest', function (): void {
    $f = BapiTestSupport::bootEnv();
    $body = ['invitations' => array_fill(0, 101, ['email_address' => 'overflow@example.com'])];

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/invitations/bulk'), $body)
        ->assertStatus(422);
});

it('lists invitations with filters', function (): void {
    Bus::fake();
    $f = BapiTestSupport::bootEnv();

    foreach (['a@example.com', 'b@example.com'] as $email) {
        $this->withHeaders(BapiTestSupport::headers($f['token']))
            ->postJson(BapiTestSupport::url('/invitations'), ['email_address' => $email]);
    }

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/invitations?status=pending&query=a@'));
    $r->assertOk()->assertJsonPath('total_count', 1);
});
