<?php

declare(strict_types=1);

use App\Models\EmailAddress;
use App\Models\Session;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Http\SignUp\SignUpTestSupport;

uses(RefreshDatabase::class);

it('runs create → prepare → attempt → complete with email + password', function (): void {
    $f = SignUpTestSupport::bootEnv();
    $bs = SignUpTestSupport::clientWithCookie($f['env']);
    $cookie = $bs['cookie'];

    // 1. POST /sign_ups with email + password — both required + email verify_at_sign_up.
    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ups', [
            'email_address' => 'newbie@example.com',
            'password' => 'super-secret-password',
        ]);

    $create->assertOk()
        ->assertJsonPath('response.status', 'missing_requirements')
        ->assertJsonPath('response.email_address', 'newbie@example.com')
        ->assertJsonPath('response.unverified_fields', ['email_address']);
    $sid = $create->json('response.id');

    // 2. POST /prepare_verification strategy=email_code.
    $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ups/{$sid}/prepare_verification", [
            'strategy' => 'email_code',
        ])
        ->assertOk();

    // 3. Stamp a known code on the row.
    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    $codeRow = VerificationCode::query()->where('verification_id', $verification->id)->latest('id')->first();
    $known = '424242';
    $codeRow->forceFill(['code_hash' => hash('sha256', $known)])->save();

    // 4. POST /attempt_verification → complete.
    $attempt = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ups/{$sid}/attempt_verification", [
            'strategy' => 'email_code',
            'code' => $known,
        ]);

    $attempt->assertOk()
        ->assertJsonPath('response.status', 'complete');
    expect($attempt->json('response.created_session_id'))->toStartWith('sess_');
    expect($attempt->json('response.created_user_id'))->toStartWith('user_');

    // 5. Verify the User + EmailAddress + Session rows exist with the right state.
    $userId = $attempt->json('response.created_user_id');
    $user = User::query()->withoutGlobalScopes()->where('id', $userId)->first();
    expect($user)->not->toBeNull();
    expect($user->checkPassword('super-secret-password'))->toBeTrue();

    $email = EmailAddress::query()->withoutGlobalScopes()->where('user_id', $userId)->first();
    expect($email)->not->toBeNull();
    expect($email->email_address)->toBe('newbie@example.com');
    expect($email->isVerified())->toBeTrue();
    expect($email->is_primary)->toBeTrue();

    $sessionId = $attempt->json('response.created_session_id');
    expect(Session::query()->withoutGlobalScopes()->where('id', $sessionId)->where('status', 'active')->exists())->toBeTrue();
});

it('returns missing_fields when first_name is required and not provided', function (): void {
    $f = SignUpTestSupport::bootEnv([
        'attributes' => [
            'first_name' => ['enabled' => true, 'required' => true],
        ],
    ]);
    $bs = SignUpTestSupport::clientWithCookie($f['env']);
    $cookie = $bs['cookie'];

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ups', [
            'email_address' => 'newbie@example.com',
            'password' => 'super-secret-password',
        ]);

    $create->assertOk()
        ->assertJsonPath('response.status', 'missing_requirements')
        ->assertJsonPath('response.missing_fields', ['first_name']);

    // No User row written until everything is supplied + verified.
    expect(User::query()->withoutGlobalScopes()->count())->toBe(0);
});
