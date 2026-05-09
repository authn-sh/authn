<?php

declare(strict_types=1);

use App\Models\Invitation;
use App\Models\Session;
use App\Models\User;
use App\Services\Tickets\TicketIssuer;
use Tests\Feature\Http\SignUp\SignUpTestSupport;


it('redeems an invitation ticket and completes the sign-up in one call', function (): void {
    $f = SignUpTestSupport::bootEnv();
    $invitation = Invitation::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'email_address' => 'invited@example.com',
        'status' => Invitation::STATUS_PENDING,
        'expires_at' => now()->addDay(),
    ]);

    $ticket = app(TicketIssuer::class)->issue($f['env'], 600, [
        'sub' => 'invited@example.com',
        'sid' => $invitation->id,
        'purpose' => 'invitation',
    ]);

    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ups', [
            'ticket' => $ticket,
            'password' => 'super-secret-password',
        ]);

    $r->assertOk()
        ->assertJsonPath('response.status', 'complete')
        ->assertJsonPath('response.email_address', 'invited@example.com');

    $userId = $r->json('response.created_user_id');
    expect($userId)->toStartWith('user_');
    $user = User::query()->withoutGlobalScopes()->where('id', $userId)->first();
    expect($user)->not->toBeNull();
    expect($user->primaryEmailAddress->isVerified())->toBeTrue();

    $sessionId = $r->json('response.created_session_id');
    expect(Session::query()->withoutGlobalScopes()->where('id', $sessionId)->where('status', 'active')->exists())->toBeTrue();

    $invitation->refresh();
    expect($invitation->status)->toBe(Invitation::STATUS_ACCEPTED);
    expect($invitation->redeemed_by_user_id)->toBe($userId);
});

it('rejects a replayed ticket with ticket_invalid', function (): void {
    $f = SignUpTestSupport::bootEnv();
    $invitation = Invitation::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'email_address' => 'replay@example.com',
        'status' => Invitation::STATUS_PENDING,
        'expires_at' => now()->addDay(),
    ]);
    $ticket = app(TicketIssuer::class)->issue($f['env'], 600, [
        'sub' => 'replay@example.com',
        'sid' => $invitation->id,
        'purpose' => 'invitation',
    ]);

    // First redemption succeeds.
    $bs = SignUpTestSupport::clientWithCookie($f['env']);
    $first = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ups', [
            'ticket' => $ticket,
            'password' => 'super-secret-password',
        ]);
    $first->assertOk();

    // Second redemption (different client) fails — single-use jti guard fires.
    $bs2 = SignUpTestSupport::clientWithCookie($f['env']);
    $second = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs2['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ups', [
            'ticket' => $ticket,
            'password' => 'super-secret-password',
        ]);
    $second->assertStatus(422)->assertJsonPath('errors.0.code', 'ticket_invalid');
});
