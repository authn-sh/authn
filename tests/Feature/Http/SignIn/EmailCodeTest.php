<?php

declare(strict_types=1);

use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Http\SignIn\SignInTestSupport;

uses(RefreshDatabase::class);

it('runs the prepare → attempt → complete email-code path end-to-end', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    // Pre-create the Client + cookie so we don't have to round-trip the
    // Set-Cookie header through the test harness's cookie jar.
    $bs = SignInTestSupport::clientWithCookie($f['env']);
    $cookie = $bs['cookie'];
    $cookieName = '__client';

    // 1. POST /sign_ins (identifier-only)
    $create = $this->withCredentials()
        ->withUnencryptedCookie($cookieName, $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ins', [
            'identifier' => 'alice@example.com',
        ]);
    $create->assertOk()->assertJsonPath('response.status', 'needs_first_factor');
    $sid = $create->json('response.id');

    // 2. POST /prepare_first_factor strategy=email_code
    $this->withCredentials()
        ->withUnencryptedCookie($cookieName, $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$sid}/prepare_first_factor", [
            'strategy' => 'email_code',
        ])
        ->assertOk();

    // 3. Pull the cleartext code from the verification_codes table by re-hashing
    //    the candidate sent in step 4.
    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    expect($verification)->not->toBeNull();
    $codeRow = VerificationCode::query()->where('verification_id', $verification->id)->latest('id')->first();
    expect($codeRow)->not->toBeNull();

    // The cleartext is gone — but for the purpose of the test we mint a new
    // code via the manager and stamp it on the row so we know the secret.
    $known = '424242';
    $codeRow->forceFill(['code_hash' => hash('sha256', $known)])->save();

    // 4. POST /attempt_first_factor with the right code → complete
    $attempt = $this->withCredentials()
        ->withUnencryptedCookie($cookieName, $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$sid}/attempt_first_factor", [
            'strategy' => 'email_code',
            'code' => $known,
        ]);

    $attempt->assertOk()->assertJsonPath('response.status', 'complete');
    expect($attempt->json('response.created_session_id'))->toStartWith('sess_');
});

it('flips the verification to failed after 5 wrong codes', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);
    $bs = SignInTestSupport::clientWithCookie($f['env']);
    $cookie = $bs['cookie'];

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ins', [
            'identifier' => 'alice@example.com',
        ]);
    $sid = $create->json('response.id');

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$sid}/prepare_first_factor", ['strategy' => 'email_code'])
        ->assertOk();

    for ($i = 0; $i < 5; $i++) {
        $r = $this->withCredentials()
            ->withUnencryptedCookie('__client', $cookie)
            ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
            ->postJson("https://acme.authn.local/v1/client/sign_ins/{$sid}/attempt_first_factor", [
                'strategy' => 'email_code',
                'code' => '000000',
            ]);
        $r->assertStatus(422);
    }

    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    expect($verification->status)->toBe('failed');
});
