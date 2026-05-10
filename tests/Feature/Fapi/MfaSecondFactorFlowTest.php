<?php

declare(strict_types=1);

use App\Auth\Mfa\BackupCodesService;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Session;
use App\Models\SignInAttempt;
use App\Models\TotpSecret;
use App\Models\User;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Http\Bapi\BapiTestSupport;
use Tests\Feature\Http\SignIn\SignInTestSupport;

/**
 * End-to-end coverage for the v0.3 second-factor sign-in flow. Walks
 * the full controller arc the way `<SignIn />` will when JS-3 lands.
 */
function flowReq(string $method, string $url, array $body, array $headers, ?string $cookie): TestResponse
{
    $req = test()->withCredentials()->withHeaders($headers);
    if ($cookie !== null) {
        $req = $req->withUnencryptedCookie('__client', $cookie);
    }

    return $req->json($method, $url, $body);
}

function seedTotpForFlow(Environment $env, User $user): TotpSecret
{
    $secret = TotpSecret::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    $user->forceFill([
        'totp_enabled' => true,
        'two_factor_enabled' => true,
        'mfa_enabled_at' => now(),
    ])->save();

    return $secret;
}

/**
 * @return list<string>
 */
function seedBackupCodesForFlow(Environment $env, User $user, int $n = 10): array
{
    app()->instance(Environment::class, $env);
    $codes = app(BackupCodesService::class)->regenerate($user, $env, $n);
    $user->forceFill(['backup_code_enabled' => true, 'two_factor_enabled' => true])->save();

    return $codes;
}

it('happy-path TOTP-only sign-in: password → needs_second_factor → totp answer → complete', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    $secret = seedTotpForFlow($f['env'], $bundle['user']);
    $cb = SignInTestSupport::clientWithCookie($f['env']);
    $headers = ['Host' => 'acme.authn.local', 'Origin' => $f['origin']];

    $r1 = flowReq('POST', 'https://acme.authn.local/v1/client/sign-ins', [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ], $headers, $cb['cookie']);

    $r1->assertOk()
        ->assertJsonPath('response.status', 'needs_second_factor')
        ->assertJsonPath('response.supported_strategies', ['totp']);
    expect($r1->json('response.created_session_id'))->toBeNull();

    $sid = $r1->json('response.id');
    $code = (new Google2FA)->getCurrentOtp($secret->secret);

    $r2 = flowReq('POST', "https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", [
        'strategy' => 'totp',
        'code' => $code,
    ], $headers, $cb['cookie']);

    $r2->assertOk();
    expect(Session::query()->withoutGlobalScopes()->where('user_id', $bundle['user']->id)->where('status', 'active')->exists())->toBeTrue();
});

it('happy-path backup-code fallback: burns one code and GET /me/backup-codes reflects 9 unspent', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    seedTotpForFlow($f['env'], $bundle['user']);
    $codes = seedBackupCodesForFlow($f['env'], $bundle['user'], 10);
    $cb = SignInTestSupport::clientWithCookie($f['env']);
    $headers = ['Host' => 'acme.authn.local', 'Origin' => $f['origin']];

    $r1 = flowReq('POST', 'https://acme.authn.local/v1/client/sign-ins', [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ], $headers, $cb['cookie']);
    expect($r1->json('response.supported_strategies'))->toContain('totp')->toContain('backup_code');
    $sid = $r1->json('response.id');

    flowReq('POST', "https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", [
        'strategy' => 'backup_code',
        'code' => $codes[0],
    ], $headers, $cb['cookie'])->assertOk();

    expect(Session::query()->withoutGlobalScopes()->where('user_id', $bundle['user']->id)->where('status', 'active')->exists())->toBeTrue();
    expect(app(BackupCodesService::class)->unspentCount($bundle['user']->fresh()))->toBe(9);
});

it('replayed backup code returns form_code_already_used (distinct from form_code_incorrect)', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    seedTotpForFlow($f['env'], $bundle['user']);
    $codes = seedBackupCodesForFlow($f['env'], $bundle['user'], 4);
    $headers = ['Host' => 'acme.authn.local', 'Origin' => $f['origin']];

    // First sign-in burns codes[0].
    $cb1 = SignInTestSupport::clientWithCookie($f['env']);
    $first = flowReq('POST', 'https://acme.authn.local/v1/client/sign-ins', [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ], $headers, $cb1['cookie']);
    flowReq('POST', "https://acme.authn.local/v1/client/sign-ins/{$first->json('response.id')}/challenges", [
        'strategy' => 'backup_code',
        'code' => $codes[0],
    ], $headers, $cb1['cookie'])->assertOk();

    // Second sign-in tries the same code — form_code_already_used.
    $cb2 = SignInTestSupport::clientWithCookie($f['env']);
    $second = flowReq('POST', 'https://acme.authn.local/v1/client/sign-ins', [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ], $headers, $cb2['cookie']);
    $replay = flowReq('POST', "https://acme.authn.local/v1/client/sign-ins/{$second->json('response.id')}/challenges", [
        'strategy' => 'backup_code',
        'code' => $codes[0],
    ], $headers, $cb2['cookie']);

    $replay->assertStatus(422)->assertJsonPath('errors.0.code', 'form_code_already_used');
});

it('wrong TOTP code returns form_code_incorrect, attempt stays in needs_second_factor', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    seedTotpForFlow($f['env'], $bundle['user']);
    $cb = SignInTestSupport::clientWithCookie($f['env']);
    $headers = ['Host' => 'acme.authn.local', 'Origin' => $f['origin']];

    $first = flowReq('POST', 'https://acme.authn.local/v1/client/sign-ins', [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ], $headers, $cb['cookie']);
    $sid = $first->json('response.id');

    $r = flowReq('POST', "https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", [
        'strategy' => 'totp',
        'code' => '000000',
    ], $headers, $cb['cookie']);
    $r->assertStatus(422)->assertJsonPath('errors.0.code', 'form_code_incorrect');

    expect(SignInAttempt::query()->withoutGlobalScopes()->where('id', $sid)->first()->status)
        ->toBe('needs_second_factor');
});

it('user without MFA enrolled completes sign-in in one round-trip', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);
    $headers = ['Host' => 'acme.authn.local', 'Origin' => $f['origin']];

    $r = flowReq('POST', 'https://acme.authn.local/v1/client/sign-ins', [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ], $headers, null);

    $r->assertOk()->assertJsonPath('response.status', 'complete');
});

it('env disable of both totp + backup_codes mid-flight falls through to complete (loose semantic)', function (): void {
    // NOTE: pending decision on env-toggle vs enrolled-user-lockout
    // semantic — see https://github.com/authn-sh/authn/issues/109.
    // This test pins the AU-5 loose semantic currently shipped:
    // operator toggle bypasses enrolled MFA. If issue #109 resolves
    // toward strict, flip this assertion to needs_second_factor.
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    seedTotpForFlow($f['env'], $bundle['user']);
    $f['env']->forceFill([
        'user_settings' => [
            'multi_factor' => [
                'totp' => ['enabled' => false],
                'backup_codes' => ['enabled' => false, 'default_count' => 10],
            ],
        ],
    ])->save();

    $r = flowReq('POST', 'https://acme.authn.local/v1/client/sign-ins', [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ], ['Host' => 'acme.authn.local', 'Origin' => $f['origin']], null);

    $r->assertOk()->assertJsonPath('response.status', 'complete');
});

it('mfa_already_verified on re-enrol after a successful verify', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    seedTotpForFlow($f['env'], $bundle['user']);

    $headers = ['Host' => 'acme.authn.local', 'Origin' => $f['origin'], 'Authorization' => 'Bearer '.signInJwt($f, $bundle['user'])];

    $r = test()->withCredentials()->withHeaders($headers)
        ->postJson('https://acme.authn.local/v1/me/totp', []);

    $r->assertStatus(409);
    expect($r->json('errors.0.code'))->toBe('mfa_already_verified');
});

it('BAPI DELETE /v1/users/{id}/mfa clears, then user signs in with first-factor only', function (): void {
    // Boot with the BAPI host setup so the BAPI DELETE works.
    $bapiBoot = BapiTestSupport::bootEnv();
    SignInTestSupport::makeUser($bapiBoot['env']);
    $signInUser = User::query()->withoutGlobalScopes()
        ->where('environment_id', $bapiBoot['env']->id)
        ->orderBy('created_at')
        ->first();
    seedTotpForFlow($bapiBoot['env'], $signInUser);

    test()->withHeaders(BapiTestSupport::headers($bapiBoot['token']))
        ->deleteJson(BapiTestSupport::url('/users/'.$signInUser->id.'/mfa'))
        ->assertOk();

    // Now reload routes for FAPI to sign in.
    SignInTestSupport::reloadRoutes();
    $headers = ['Host' => $bapiBoot['env']->slug.'.authn.local', 'Origin' => 'https://app.example.com'];

    $r = test()->withCredentials()->withHeaders($headers)
        ->postJson('https://'.$bapiBoot['env']->slug.'.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
            'strategy' => 'password',
            'password' => 'super-secret-password',
        ]);

    $r->assertOk()->assertJsonPath('response.status', 'complete');
});

function signInJwt(array $f, User $user): string
{
    // Use MeTestSupport pattern — mint a session token JWT for the user.
    $client = Client::create(['environment_id' => $f['env']->id]);
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
    ]);
    $minted = app(SessionTokenIssuer::class)->mint($session->fresh());

    return $minted['jwt'];
}
