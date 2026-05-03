<?php

declare(strict_types=1);

use App\Models\Session;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Http\SignIn\SignInTestSupport;

uses(RefreshDatabase::class);

it('runs the reset_password_email_code → reset_password → complete path end-to-end', function (): void {
    Bus::fake();
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    $bs = SignInTestSupport::clientWithCookie($f['env']);
    $cookie = $bs['cookie'];

    // 1. Open the attempt with strategy=reset_password_email_code (no prepare needed
    //    yet — just stash the strategy intent).
    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ins', [
            'identifier' => 'alice@example.com',
        ]);
    $sid = $create->json('response.id');

    // 2. Prepare the reset code.
    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$sid}/prepare_first_factor", [
            'strategy' => 'reset_password_email_code',
        ])
        ->assertOk();

    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    $codeRow = VerificationCode::query()->where('verification_id', $verification->id)->latest('id')->first();
    $known = '424242';
    $codeRow->forceFill(['code_hash' => hash('sha256', $known)])->save();

    // 3. Attempt with the right code → status flips to needs_new_password.
    $attempt = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$sid}/attempt_first_factor", [
            'strategy' => 'reset_password_email_code',
            'code' => $known,
        ]);
    $attempt->assertOk()->assertJsonPath('response.status', 'needs_new_password');

    // 4. Submit a new password → complete + Session minted.
    $reset = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$sid}/reset_password", [
            'password' => 'brand-new-password-9000',
        ]);
    $reset->assertOk()->assertJsonPath('response.status', 'complete');

    // 5. The new password works.
    $user = User::query()->withoutGlobalScopes()->where('id', $bundle['user']->id)->first();
    expect($user->checkPassword('brand-new-password-9000'))->toBeTrue();
    expect($user->checkPassword('super-secret-password'))->toBeFalse();
});

it('refuses /reset_password unless the attempt is in needs_new_password state', function (): void {
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
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$sid}/reset_password", [
            'password' => 'whatever',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'not_in_needs_new_password_state');
});
