<?php

declare(strict_types=1);

use App\Models\BackupCode;
use App\Models\TotpSecret;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Http\Bapi\BapiTestSupport;
use Tests\Feature\Http\Me\MeTestSupport;

function totpReqAudit(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

it('logs auth.mfa.totp_enrolled on successful FAPI verify', function (): void {
    Log::spy();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    totpReqAudit('POST', '/me/totp', $auth['jwt'])->assertOk();
    $row = TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->first();
    $code = (new Google2FA)->getCurrentOtp($row->secret);

    totpReqAudit('POST', '/me/totp/verify', $auth['jwt'], ['code' => $code])->assertOk();

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $ctx) use ($auth, $f): bool {
        return $message === 'auth.mfa.totp_enrolled'
            && $ctx['user_id'] === $auth['user']->id
            && $ctx['environment_id'] === $f['env']->id
            && $ctx['surface'] === 'fapi'
            && $ctx['actor_type'] === 'user';
    })->once();
});

it('logs auth.mfa.totp_removed on FAPI DELETE /me/totp', function (): void {
    Log::spy();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    $auth['user']->forceFill(['totp_enabled' => true, 'two_factor_enabled' => true])->save();

    totpReqAudit('DELETE', '/me/totp', $auth['jwt'])->assertOk();

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $ctx) use ($auth, $f): bool {
        return $message === 'auth.mfa.totp_removed'
            && $ctx['user_id'] === $auth['user']->id
            && $ctx['environment_id'] === $f['env']->id
            && $ctx['surface'] === 'fapi';
    })->once();
});

it('logs auth.mfa.backup_codes_generated with the count on FAPI regenerate', function (): void {
    Log::spy();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);

    totpReqAudit('POST', '/me/backup-codes', $auth['jwt'])->assertOk();

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $ctx) use ($auth, $f): bool {
        return $message === 'auth.mfa.backup_codes_generated'
            && $ctx['user_id'] === $auth['user']->id
            && $ctx['environment_id'] === $f['env']->id
            && $ctx['surface'] === 'fapi'
            && $ctx['count'] === 10;
    })->once();
});

it('logs auth.mfa.totp_removed with surface=bapi on operator nuke', function (): void {
    Log::spy();
    $f = BapiTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    BackupCode::create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'code_hash' => Hash::make('plain-1'),
    ]);
    $user->forceFill([
        'totp_enabled' => true,
        'backup_code_enabled' => true,
        'two_factor_enabled' => true,
    ])->save();

    test()->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/users/'.$user->id.'/mfa'))
        ->assertOk();

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $ctx) use ($user, $f): bool {
        return $message === 'auth.mfa.totp_removed'
            && $ctx['user_id'] === $user->id
            && $ctx['environment_id'] === $f['env']->id
            && $ctx['surface'] === 'bapi'
            && $ctx['actor_type'] === 'api_key';
    })->once();
});

it('does not log auth.mfa.totp_removed when BAPI nukes a user with no MFA', function (): void {
    Log::spy();
    $f = BapiTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);

    test()->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/users/'.$user->id.'/mfa'))
        ->assertOk();

    Log::shouldNotHaveReceived('info', ['auth.mfa.totp_removed', Mockery::any()]);
});
