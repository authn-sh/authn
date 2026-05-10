<?php

declare(strict_types=1);

use App\Jobs\Mail\SendBackupCodesGeneratedNotification;
use App\Jobs\Mail\SendMfaDisabledNotification;
use App\Jobs\Mail\SendTotpEnabledNotification;
use App\Models\BackupCode;
use App\Models\EmailTemplate;
use App\Models\TotpSecret;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Http\Bapi\BapiTestSupport;
use Tests\Feature\Http\Me\MeTestSupport;

function totpReqMail(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

it('queues SendTotpEnabledNotification on a successful TOTP verify', function (): void {
    Bus::fake();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    totpReqMail('POST', '/me/totp', $auth['jwt'])->assertOk();
    $row = TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->first();
    $code = (new Google2FA)->getCurrentOtp($row->secret);

    totpReqMail('POST', '/me/totp/verify', $auth['jwt'], ['code' => $code])->assertOk();

    Bus::assertDispatched(SendTotpEnabledNotification::class, fn ($job) => $job->userId === $auth['user']->id);
});

it('queues SendBackupCodesGeneratedNotification with the count on regenerate', function (): void {
    Bus::fake();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);

    totpReqMail('POST', '/me/backup-codes', $auth['jwt'])->assertOk();

    Bus::assertDispatched(
        SendBackupCodesGeneratedNotification::class,
        fn ($job) => $job->userId === $auth['user']->id && $job->count === 10,
    );
});

it('queues SendMfaDisabledNotification when DELETE /me/totp removes the last factor', function (): void {
    Bus::fake();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    $auth['user']->forceFill([
        'totp_enabled' => true,
        'two_factor_enabled' => true,
        'mfa_enabled_at' => now(),
    ])->save();

    totpReqMail('DELETE', '/me/totp', $auth['jwt'])->assertOk();

    Bus::assertDispatched(SendMfaDisabledNotification::class, fn ($job) => $job->userId === $auth['user']->id);
});

it('does not queue SendMfaDisabledNotification when DELETE /me/totp leaves backup codes behind', function (): void {
    Bus::fake();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    BackupCode::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'code_hash' => Hash::make('plain-1'),
    ]);
    $auth['user']->forceFill([
        'totp_enabled' => true,
        'backup_code_enabled' => true,
        'two_factor_enabled' => true,
        'mfa_enabled_at' => now(),
    ])->save();

    totpReqMail('DELETE', '/me/totp', $auth['jwt'])->assertOk();

    Bus::assertNotDispatched(SendMfaDisabledNotification::class);
});

it('queues SendMfaDisabledNotification when DELETE /me/backup-codes removes the last factor', function (): void {
    Bus::fake();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    BackupCode::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'code_hash' => Hash::make('plain-1'),
    ]);
    $auth['user']->forceFill([
        'backup_code_enabled' => true,
        'two_factor_enabled' => true,
        'mfa_enabled_at' => now(),
    ])->save();

    totpReqMail('DELETE', '/me/backup-codes', $auth['jwt'])->assertOk();

    Bus::assertDispatched(SendMfaDisabledNotification::class, fn ($job) => $job->userId === $auth['user']->id);
});

it('queues SendMfaDisabledNotification when BAPI DELETE /users/{id}/mfa nukes an enrolled user', function (): void {
    Bus::fake();
    $f = BapiTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    $user->forceFill(['totp_enabled' => true, 'two_factor_enabled' => true])->save();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/users/'.$user->id.'/mfa'))
        ->assertOk();

    Bus::assertDispatched(SendMfaDisabledNotification::class, fn ($job) => $job->userId === $user->id);
});

it('does not queue SendMfaDisabledNotification when BAPI DELETE /users/{id}/mfa hits a non-enrolled user', function (): void {
    Bus::fake();
    $f = BapiTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/users/'.$user->id.'/mfa'))
        ->assertOk();

    Bus::assertNotDispatched(SendMfaDisabledNotification::class);
});

it('seeds the new email templates for newly created environments', function (): void {
    $f = MeTestSupport::bootEnv();
    $slugs = EmailTemplate::query()
        ->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->pluck('slug')
        ->all();
    expect($slugs)->toContain('totp_enabled');
    expect($slugs)->toContain('mfa_disabled');
    expect($slugs)->toContain('backup_codes_generated');
});
