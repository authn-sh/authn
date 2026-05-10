<?php

declare(strict_types=1);

use App\Auth\Mfa\BackupCodesService;
use App\Models\BackupCode;
use App\Models\Environment;
use App\Models\TotpSecret;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

function bcReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

function enrolVerifiedTotp(string $environmentId, string $userId): TotpSecret
{
    return TotpSecret::create([
        'environment_id' => $environmentId,
        'user_id' => $userId,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
}

it('POST /me/backup-codes returns N codes matching xxxx-xxxx, hashed at rest, prior unspent rows wiped', function (): void {
    Queue::fake();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    enrolVerifiedTotp($f['env']->id, $auth['user']->id);

    $r = bcReq('POST', '/me/backup-codes', $auth['jwt']);

    $r->assertOk();
    expect($r->json('response.object'))->toBe('backup_code_batch');
    expect($r->json('response.user_id'))->toBe($auth['user']->id);
    expect($r->json('response.count'))->toBe(10);
    $codes = $r->json('response.codes');
    expect($codes)->toHaveCount(10);
    foreach ($codes as $code) {
        expect($code)->toMatch('/^[a-z0-9]{4}-[a-z0-9]{4}$/');
    }
    expect(array_unique($codes))->toHaveCount(10);

    $rowCount = BackupCode::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->count();
    expect($rowCount)->toBe(10);
    expect(User::query()->withoutGlobalScopes()->where('id', $auth['user']->id)->first()->backup_code_enabled)->toBeTrue();
});

it('POST /me/backup-codes again replaces every prior unspent row', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    enrolVerifiedTotp($f['env']->id, $auth['user']->id);

    bcReq('POST', '/me/backup-codes', $auth['jwt'])->assertOk();
    $first = BackupCode::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->pluck('id')->all();

    bcReq('POST', '/me/backup-codes', $auth['jwt'])->assertOk();
    $second = BackupCode::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->pluck('id')->all();

    expect(array_intersect($first, $second))->toBe([]);
    expect($second)->toHaveCount(10);
});

it('BackupCodesService::consume succeeds once and rejects replays', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    enrolVerifiedTotp($f['env']->id, $auth['user']->id);

    app()->instance(Environment::class, $f['env']);
    $codes = app(BackupCodesService::class)->regenerate($auth['user'], $f['env'], 4);
    $service = app(BackupCodesService::class);

    expect($service->consume($auth['user'], $codes[0]))->toBeTrue();
    expect($service->consume($auth['user'], $codes[0]))->toBeFalse();
    expect($service->consume($auth['user'], $codes[1]))->toBeTrue();
    expect($service->unspentCount($auth['user']))->toBe(2);
});

it('POST /me/backup-codes returns 422 mfa_not_enabled when backup_codes.enabled is false', function (): void {
    $f = MeTestSupport::bootEnv([
        'multi_factor' => ['totp' => ['enabled' => true], 'backup_codes' => ['enabled' => false, 'default_count' => 10]],
    ]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    enrolVerifiedTotp($f['env']->id, $auth['user']->id);

    $r = bcReq('POST', '/me/backup-codes', $auth['jwt']);

    $r->assertStatus(422);
    expect($r->json('errors.0.code'))->toBe('mfa_not_enabled');
});

it('POST /me/backup-codes returns 422 mfa_primary_factor_required when no verified TOTP', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = bcReq('POST', '/me/backup-codes', $auth['jwt']);

    $r->assertStatus(422);
    expect($r->json('errors.0.code'))->toBe('mfa_primary_factor_required');
});

it('GET /me/backup-codes returns the unspent count without re-surfacing plaintext', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    enrolVerifiedTotp($f['env']->id, $auth['user']->id);
    bcReq('POST', '/me/backup-codes', $auth['jwt'])->assertOk();

    $r = bcReq('GET', '/me/backup-codes', $auth['jwt']);

    $r->assertOk();
    expect($r->json('response.count'))->toBe(10);
    expect($r->json('response.codes'))->toBe([]);
});

it('DELETE /me/backup-codes empties the batch, flips backup_code_enabled, and is idempotent', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    enrolVerifiedTotp($f['env']->id, $auth['user']->id);
    bcReq('POST', '/me/backup-codes', $auth['jwt'])->assertOk();

    $r = bcReq('DELETE', '/me/backup-codes', $auth['jwt']);
    $r->assertOk();
    expect($r->json('response.count'))->toBe(0);
    expect($r->json('response.codes'))->toBe([]);

    $r2 = bcReq('DELETE', '/me/backup-codes', $auth['jwt']);
    $r2->assertOk();
    expect($r2->json('response.count'))->toBe(0);
});

it('DELETE /me/backup-codes flips two_factor_enabled off when no TOTP remains', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    enrolVerifiedTotp($f['env']->id, $auth['user']->id);
    bcReq('POST', '/me/backup-codes', $auth['jwt'])->assertOk();
    TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->delete();

    bcReq('DELETE', '/me/backup-codes', $auth['jwt'])->assertOk();

    $user = User::query()->withoutGlobalScopes()->where('id', $auth['user']->id)->first();
    expect($user->backup_code_enabled)->toBeFalse();
    expect($user->two_factor_enabled)->toBeFalse();
    expect($user->mfa_disabled_at)->not->toBeNull();
});
