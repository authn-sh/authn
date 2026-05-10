<?php

declare(strict_types=1);

use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Http\SignIn\SignInTestSupport;

it('runs the reset_password_email_code → set new password → complete path end-to-end', function (): void {
    Bus::fake();
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    $bs = SignInTestSupport::clientWithCookie($f['env']);
    $cookie = $bs['cookie'];

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);
    $sid = $create->json('response.id');

    // 1. Issue a reset-password challenge.
    $issue = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", [
            'strategy' => 'reset_password_email_code',
        ]);
    $issue->assertOk()->assertJsonPath('response.strategy', 'reset_password_email_code');
    $cid = $issue->json('response.id');

    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    $codeRow = VerificationCode::query()->where('verification_id', $verification->id)->latest('id')->first();
    $known = '424242';
    $codeRow->forceFill(['code_hash' => hash('sha256', $known)])->save();

    // 2. Answer the challenge — SignIn flips to needs_new_password.
    $answer = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges/{$cid}/answer", [
            'code' => $known,
        ]);
    $answer->assertOk()->assertJsonPath('response.status', 'verified');

    expect(SignInAttempt::query()->withoutGlobalScopes()->where('id', $sid)->firstOrFail()->status)
        ->toBe('needs_new_password');

    // 3. PATCH the SignIn with the new password — completes + Session minted.
    $reset = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->patchJson("https://acme.authn.local/v1/client/sign-ins/{$sid}", [
            'password' => 'brand-new-password-9000',
        ]);
    $reset->assertOk()->assertJsonPath('response.status', 'complete');

    // 4. The new password works.
    $user = User::query()->withoutGlobalScopes()->where('id', $bundle['user']->id)->first();
    expect($user->checkPassword('brand-new-password-9000'))->toBeTrue();
    expect($user->checkPassword('super-secret-password'))->toBeFalse();
});

it('refuses PATCH /sign-ins/{sid} with password unless the attempt is in needs_new_password state', function (): void {
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

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->patchJson("https://acme.authn.local/v1/client/sign-ins/{$sid}", [
            'password' => 'whatever',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'not_in_needs_new_password_state');
});
