<?php

declare(strict_types=1);

use App\Models\BackupCode;
use App\Models\TotpSecret;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Http\Bapi\BapiTestSupport;

function makeUserWithTotp(string $envId): array
{
    $user = User::create(['environment_id' => $envId]);
    $secret = TotpSecret::create([
        'environment_id' => $envId,
        'user_id' => $user->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    $user->forceFill([
        'totp_enabled' => true,
        'two_factor_enabled' => true,
        'mfa_enabled_at' => now(),
    ])->save();

    return ['user' => $user->fresh(), 'secret' => $secret];
}

it('POST /users/{id}/verify-totp returns {verified: true} on a fresh code', function (): void {
    $f = BapiTestSupport::bootEnv();
    $bundle = makeUserWithTotp($f['env']->id);
    $code = (new Google2FA)->getCurrentOtp($bundle['secret']->secret);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users/'.$bundle['user']->id.'/verify-totp'), ['code' => $code]);

    $r->assertOk()->assertExactJson(['verified' => true]);
});

it('POST /users/{id}/verify-totp returns {verified: false} on a wrong code', function (): void {
    $f = BapiTestSupport::bootEnv();
    $bundle = makeUserWithTotp($f['env']->id);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users/'.$bundle['user']->id.'/verify-totp'), ['code' => '000000']);

    $r->assertOk()->assertExactJson(['verified' => false]);
});

it('POST /users/{id}/verify-totp does not advance last_used_step (read-only check)', function (): void {
    $f = BapiTestSupport::bootEnv();
    $bundle = makeUserWithTotp($f['env']->id);
    $code = (new Google2FA)->getCurrentOtp($bundle['secret']->secret);

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users/'.$bundle['user']->id.'/verify-totp'), ['code' => $code])
        ->assertOk();

    $secret = TotpSecret::query()->withoutGlobalScopes()->where('id', $bundle['secret']->id)->first();
    expect($secret->last_used_step)->toBeNull();
});

it('POST /users/{id}/verify-totp returns 404 totp_not_found when the user has no enrolled TOTP', function (): void {
    $f = BapiTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users/'.$user->id.'/verify-totp'), ['code' => '123456']);

    $r->assertStatus(404);
    expect($r->json('errors.0.code'))->toBe('totp_not_found');
});

it('POST /users/{id}/verify-totp returns 404 user_not_found for an unknown user', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users/user_DOES_NOT_EXIST/verify-totp'), ['code' => '123456']);

    $r->assertStatus(404);
    expect($r->json('errors.0.code'))->toBe('user_not_found');
});

it('POST /users/{id}/verify-totp returns 422 mfa_not_enabled when totp.enabled is false', function (): void {
    $f = BapiTestSupport::bootEnv();
    $f['env']->forceFill([
        'user_settings' => array_merge((array) $f['env']->user_settings, [
            'multi_factor' => ['totp' => ['enabled' => false], 'backup_codes' => ['enabled' => true, 'default_count' => 10]],
        ]),
    ])->save();
    $bundle = makeUserWithTotp($f['env']->id);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users/'.$bundle['user']->id.'/verify-totp'), ['code' => '123456']);

    $r->assertStatus(422);
    expect($r->json('errors.0.code'))->toBe('mfa_not_enabled');
});

it('POST /users/{id}/verify-totp 422s on missing code', function (): void {
    $f = BapiTestSupport::bootEnv();
    $bundle = makeUserWithTotp($f['env']->id);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users/'.$bundle['user']->id.'/verify-totp'), []);

    $r->assertStatus(422);
});

it('DELETE /users/{id}/mfa drops every TotpSecret + BackupCode and flips the User flags', function (): void {
    $f = BapiTestSupport::bootEnv();
    $bundle = makeUserWithTotp($f['env']->id);
    BackupCode::create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'code_hash' => Hash::make('plain-1'),
    ]);
    $bundle['user']->forceFill(['backup_code_enabled' => true])->save();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/users/'.$bundle['user']->id.'/mfa'));

    $r->assertOk();
    expect(TotpSecret::query()->withoutGlobalScopes()->where('user_id', $bundle['user']->id)->count())->toBe(0);
    expect(BackupCode::query()->withoutGlobalScopes()->where('user_id', $bundle['user']->id)->whereNull('consumed_at')->count())->toBe(0);

    $user = User::query()->withoutGlobalScopes()->where('id', $bundle['user']->id)->first();
    expect($user->totp_enabled)->toBeFalse();
    expect($user->backup_code_enabled)->toBeFalse();
    expect($user->two_factor_enabled)->toBeFalse();
    expect($user->mfa_disabled_at)->not->toBeNull();
});

it('DELETE /users/{id}/mfa is idempotent on a user with no enrolled MFA', function (): void {
    $f = BapiTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/users/'.$user->id.'/mfa'));

    $r->assertOk();
    $r->assertJsonPath('id', $user->id);
});

it('DELETE /users/{id}/mfa returns 404 user_not_found for an unknown user', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/users/user_DOES_NOT_EXIST/mfa'));

    $r->assertStatus(404);
    expect($r->json('errors.0.code'))->toBe('user_not_found');
});
