<?php

declare(strict_types=1);

use App\Auth\Mfa\BackupCodesService;
use App\Models\Environment;
use App\Models\Session;
use App\Models\TotpSecret;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Http\SignIn\SignInTestSupport;

function signInPost(array $env, array $body, ?string $cookie = null): TestResponse
{
    $req = test()->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $env['origin']]);
    if ($cookie !== null) {
        $req = $req->withUnencryptedCookie('__client', $cookie);
    }

    return $req->postJson('https://acme.authn.local/v1/client/sign-ins', $body);
}

function signInChallenge(string $sid, array $env, array $body, string $cookie): TestResponse
{
    return test()->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $env['origin']])
        ->withUnencryptedCookie('__client', $cookie)
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", $body);
}

it('a user without MFA enrolled completes sign-in in one round-trip with password', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);

    signInPost($f, ['identifier' => 'alice@example.com', 'strategy' => 'password', 'password' => 'super-secret-password'])
        ->assertOk()
        ->assertJsonPath('response.status', 'complete')
        ->assertJsonMissingPath('response.supported_strategies.totp');
});

it('a user with verified TOTP pivots to needs_second_factor with supported_strategies = [totp]', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    $secret = TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    $bundle['user']->forceFill(['totp_enabled' => true, 'two_factor_enabled' => true])->save();

    $r = signInPost($f, ['identifier' => 'alice@example.com', 'strategy' => 'password', 'password' => 'super-secret-password']);

    $r->assertOk()
        ->assertJsonPath('response.status', 'needs_second_factor')
        ->assertJsonPath('response.supported_strategies', ['totp']);
    expect($r->json('response.created_session_id'))->toBeNull();
});

it('answers the second-factor TOTP challenge inline and completes the sign-in', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    $secret = TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    $bundle['user']->forceFill(['totp_enabled' => true, 'two_factor_enabled' => true])->save();

    $cb = SignInTestSupport::clientWithCookie($f['env']);
    $first = signInPost($f, ['identifier' => 'alice@example.com', 'strategy' => 'password', 'password' => 'super-secret-password'], $cb['cookie']);
    $sid = $first->json('response.id');

    $code = (new Google2FA)->getCurrentOtp($secret->secret);
    $r = signInChallenge($sid, $f, ['strategy' => 'totp', 'code' => $code], $cb['cookie']);

    $r->assertOk();
    $sessionId = $r->json('response.parent.created_session_id') ?? $r->json('response.created_session_id');
    expect(Session::query()->withoutGlobalScopes()->where('user_id', $bundle['user']->id)->where('status', 'active')->exists())->toBeTrue();
});

it('rejects a wrong TOTP code with form_code_incorrect and stays in needs_second_factor', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    $bundle['user']->forceFill(['totp_enabled' => true, 'two_factor_enabled' => true])->save();

    $cb = SignInTestSupport::clientWithCookie($f['env']);
    $first = signInPost($f, ['identifier' => 'alice@example.com', 'strategy' => 'password', 'password' => 'super-secret-password'], $cb['cookie']);
    $sid = $first->json('response.id');

    $r = signInChallenge($sid, $f, ['strategy' => 'totp', 'code' => '000000'], $cb['cookie']);

    $r->assertStatus(422)->assertJsonPath('errors.0.code', 'form_code_incorrect');
});

it('answers the second-factor backup-code challenge inline and completes the sign-in', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    app()->instance(Environment::class, $f['env']);
    $codes = app(BackupCodesService::class)->regenerate($bundle['user'], $f['env'], 4);
    $bundle['user']->forceFill([
        'totp_enabled' => true,
        'backup_code_enabled' => true,
        'two_factor_enabled' => true,
    ])->save();

    $cb = SignInTestSupport::clientWithCookie($f['env']);
    $first = signInPost($f, ['identifier' => 'alice@example.com', 'strategy' => 'password', 'password' => 'super-secret-password'], $cb['cookie']);
    $sid = $first->json('response.id');

    expect($first->json('response.supported_strategies'))->toContain('totp')->toContain('backup_code');

    $r = signInChallenge($sid, $f, ['strategy' => 'backup_code', 'code' => $codes[0]], $cb['cookie']);

    $r->assertOk();
    expect(Session::query()->withoutGlobalScopes()->where('user_id', $bundle['user']->id)->where('status', 'active')->exists())->toBeTrue();
});

it('rejects a replayed backup code with form_code_already_used on the second use', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    app()->instance(Environment::class, $f['env']);
    $codes = app(BackupCodesService::class)->regenerate($bundle['user'], $f['env'], 4);
    $bundle['user']->forceFill([
        'totp_enabled' => true,
        'backup_code_enabled' => true,
        'two_factor_enabled' => true,
    ])->save();

    // First sign-in consumes codes[0].
    $cb1 = SignInTestSupport::clientWithCookie($f['env']);
    $first = signInPost($f, ['identifier' => 'alice@example.com', 'strategy' => 'password', 'password' => 'super-secret-password'], $cb1['cookie']);
    signInChallenge($first->json('response.id'), $f, ['strategy' => 'backup_code', 'code' => $codes[0]], $cb1['cookie'])->assertOk();

    // Second sign-in tries codes[0] again — must be rejected.
    $cb2 = SignInTestSupport::clientWithCookie($f['env']);
    $second = signInPost($f, ['identifier' => 'alice@example.com', 'strategy' => 'password', 'password' => 'super-secret-password'], $cb2['cookie']);
    $replay = signInChallenge($second->json('response.id'), $f, ['strategy' => 'backup_code', 'code' => $codes[0]], $cb2['cookie']);

    $replay->assertStatus(422)->assertJsonPath('errors.0.code', 'form_code_already_used');
});

it('omits second_factors when the env disables both totp and backup_codes mid-flight', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    $bundle['user']->forceFill(['totp_enabled' => true, 'two_factor_enabled' => true])->save();

    $f['env']->forceFill([
        'user_settings' => [
            'multi_factor' => [
                'totp' => ['enabled' => false],
                'backup_codes' => ['enabled' => false, 'default_count' => 10],
            ],
        ],
    ])->save();

    $r = signInPost($f, ['identifier' => 'alice@example.com', 'strategy' => 'password', 'password' => 'super-secret-password']);

    $r->assertOk()->assertJsonPath('response.status', 'complete');
});

it('narrows supported_strategies to [backup_code] when totp.enabled is false but the user has backup codes', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    app()->instance(Environment::class, $f['env']);
    app(BackupCodesService::class)->regenerate($bundle['user'], $f['env'], 4);
    $bundle['user']->forceFill([
        'totp_enabled' => true,
        'backup_code_enabled' => true,
        'two_factor_enabled' => true,
    ])->save();
    $f['env']->forceFill([
        'user_settings' => [
            'multi_factor' => [
                'totp' => ['enabled' => false],
                'backup_codes' => ['enabled' => true, 'default_count' => 10],
            ],
        ],
    ])->save();

    $r = signInPost($f, ['identifier' => 'alice@example.com', 'strategy' => 'password', 'password' => 'super-secret-password']);

    $r->assertOk()
        ->assertJsonPath('response.status', 'needs_second_factor')
        ->assertJsonPath('response.supported_strategies', ['backup_code']);
});
