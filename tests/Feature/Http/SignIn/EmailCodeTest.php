<?php

declare(strict_types=1);

use App\Models\SignInAttempt;
use App\Models\Verification;
use App\Models\VerificationCode;
use Tests\Feature\Http\SignIn\SignInTestSupport;

it('runs the create-challenge → answer → complete email-code path end-to-end', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    $bs = SignInTestSupport::clientWithCookie($f['env']);
    $cookie = $bs['cookie'];
    $cookieName = '__client';

    // 1. POST /sign-ins (identifier-only)
    $create = $this->withCredentials()
        ->withUnencryptedCookie($cookieName, $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);
    $create->assertOk()->assertJsonPath('response.status', 'needs_first_factor');
    $sid = $create->json('response.id');

    // 2. POST /challenges strategy=email_code — issues the code.
    $issue = $this->withCredentials()
        ->withUnencryptedCookie($cookieName, $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", [
            'strategy' => 'email_code',
        ]);
    $issue->assertOk()
        ->assertJsonPath('response.object', 'challenge')
        ->assertJsonPath('response.strategy', 'email_code')
        ->assertJsonPath('response.status', 'pending')
        ->assertJsonPath('response.step', 'first');
    $cid = $issue->json('response.id');

    // 3. Stamp a known cleartext on the persisted code so the answer call matches.
    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    expect($verification)->not->toBeNull();
    $codeRow = VerificationCode::query()->where('verification_id', $verification->id)->latest('id')->first();
    expect($codeRow)->not->toBeNull();
    $known = '424242';
    $codeRow->forceFill(['code_hash' => hash('sha256', $known)])->save();

    // 4. POST /challenges/{cid}/answer with the right code → SignIn complete.
    $answer = $this->withCredentials()
        ->withUnencryptedCookie($cookieName, $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges/{$cid}/answer", [
            'code' => $known,
        ]);

    $answer->assertOk()
        ->assertJsonPath('response.status', 'verified');

    $signIn = SignInAttempt::query()->withoutGlobalScopes()->where('id', $sid)->firstOrFail();
    expect($signIn->status)->toBe('complete');
    expect($signIn->created_session_id)->toStartWith('sess_');
});

it('flips the challenge to failed after 5 wrong codes', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);
    $bs = SignInTestSupport::clientWithCookie($f['env']);
    $cookie = $bs['cookie'];

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);
    $sid = $create->json('response.id');

    $issue = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", ['strategy' => 'email_code']);
    $issue->assertOk();
    $cid = $issue->json('response.id');

    for ($i = 0; $i < 5; $i++) {
        $r = $this->withCredentials()
            ->withUnencryptedCookie('__client', $cookie)
            ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
            ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges/{$cid}/answer", [
                'code' => '000000',
            ]);
        $r->assertStatus(422);
    }

    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    expect($verification->status)->toBe('failed');
});
