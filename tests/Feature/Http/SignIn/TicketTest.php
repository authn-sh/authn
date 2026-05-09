<?php

declare(strict_types=1);

use App\Services\Tickets\TicketIssuer;
use Tests\Feature\Http\SignIn\SignInTestSupport;

it('redeems a sign_in_token ticket and lands on complete', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);

    $ticket = app(TicketIssuer::class)->issue($f['env'], 1800, [
        'sub' => $bundle['user']->id,
        'sid' => 'sit_01HKX9SY9V7H7TF8C8K7J9X4ZB',
        'purpose' => 'sign_in_token',
    ]);

    $response = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'strategy' => 'ticket',
            'ticket' => $ticket,
        ]);

    $response->assertOk()->assertJsonPath('response.status', 'complete');
    expect($response->json('response.created_session_id'))->toStartWith('sess_');
});

it('refuses a replayed ticket (single-use jti)', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);

    $ticket = app(TicketIssuer::class)->issue($f['env'], 1800, [
        'sub' => $bundle['user']->id,
        'sid' => 'sit_01HKX9SY9V7H7TF8C8K7J9X4ZC',
        'purpose' => 'sign_in_token',
    ]);

    // First redemption succeeds.
    $first = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'strategy' => 'ticket',
            'ticket' => $ticket,
        ]);
    $first->assertOk();

    // Second is rejected — but only after the new attempt is created (since the
    // `current_sign_in_attempt_id` short-circuit returns the previous COMPLETE
    // attempt). We force a new client / attempt to make the replay obvious.
    $second = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'strategy' => 'ticket',
            'ticket' => $ticket,
        ]);

    $second->assertStatus(401)->assertJsonPath('errors.0.code', 'ticket_invalid');
});

it('redeems an invitation ticket whose sub is the user email', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);

    $ticket = app(TicketIssuer::class)->issue($f['env'], 1800, [
        'sub' => 'alice@example.com',
        'sid' => 'inv_01HKX9SY9V7H7TF8C8K7J9X4ZB',
        'purpose' => 'invitation',
    ]);

    $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'strategy' => 'ticket',
            'ticket' => $ticket,
        ])
        ->assertOk()
        ->assertJsonPath('response.status', 'complete');
});

it('refuses a malformed ticket', function (): void {
    $f = SignInTestSupport::bootEnv();

    $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'strategy' => 'ticket',
            'ticket' => 'not-a-jwt',
        ])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'ticket_invalid');
});
