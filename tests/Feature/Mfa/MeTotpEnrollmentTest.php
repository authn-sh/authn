<?php

declare(strict_types=1);

use App\Models\TotpSecret;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Http\Me\MeTestSupport;

function totpReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

it('POST /me/totp returns secret + otpauth_uri + qr_code_data_url and persists an unverified row', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = totpReq('POST', '/me/totp', $auth['jwt']);

    $r->assertOk();
    expect($r->json('response.object'))->toBe('totp_secret');
    expect($r->json('response.user_id'))->toBe($auth['user']->id);
    expect($r->json('response.secret'))->toBeString()->not->toBe('');
    expect($r->json('response.otpauth_uri'))->toStartWith('otpauth://totp/');
    expect($r->json('response.qr_code_data_url'))->toStartWith('data:image/svg+xml;base64,');
    expect($r->json('response.verified_at'))->toBeNull();

    $row = TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->first();
    expect($row)->not->toBeNull();
    expect($row->verified_at)->toBeNull();
    expect($row->secret)->toBe($r->json('response.secret'));
});

it('POST /me/totp again while unverified overwrites the existing row in place', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $first = totpReq('POST', '/me/totp', $auth['jwt'])->assertOk();
    $second = totpReq('POST', '/me/totp', $auth['jwt'])->assertOk();

    expect($second->json('response.secret'))->not->toBe($first->json('response.secret'));
    $count = TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->count();
    expect($count)->toBe(1);
});

it('POST /me/totp/verify with a fresh code flips verified_at and User.totp_enabled', function (): void {
    Queue::fake();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    totpReq('POST', '/me/totp', $auth['jwt'])->assertOk();
    $row = TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->first();
    $code = (new Google2FA)->getCurrentOtp($row->secret);

    $r = totpReq('POST', '/me/totp/verify', $auth['jwt'], ['code' => $code]);

    $r->assertOk();
    expect($r->json('response.verified_at'))->toBeInt();
    $row->refresh();
    expect($row->verified_at)->not->toBeNull();
    expect($row->last_used_step)->not->toBeNull();
    $user = User::query()->withoutGlobalScopes()->where('id', $auth['user']->id)->first();
    expect($user->totp_enabled)->toBeTrue();
    expect($user->two_factor_enabled)->toBeTrue();
    expect($user->mfa_enabled_at)->not->toBeNull();
});

it('POST /me/totp/verify rejects an incorrect code with form_code_incorrect', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    totpReq('POST', '/me/totp', $auth['jwt'])->assertOk();

    $r = totpReq('POST', '/me/totp/verify', $auth['jwt'], ['code' => '000000']);

    $r->assertStatus(422);
    expect($r->json('errors.0.code'))->toBe('form_code_incorrect');
});

it('POST /me/totp/verify rejects a replayed code on second submission', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    totpReq('POST', '/me/totp', $auth['jwt'])->assertOk();
    $row = TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->first();
    $code = (new Google2FA)->getCurrentOtp($row->secret);

    totpReq('POST', '/me/totp/verify', $auth['jwt'], ['code' => $code])->assertOk();

    $replay = totpReq('POST', '/me/totp/verify', $auth['jwt'], ['code' => $code]);

    expect($replay->status())->toBeIn([409, 422]);
    if ($replay->status() === 409) {
        expect($replay->json('errors.0.code'))->toBe('mfa_already_verified');
    }
});

it('POST /me/totp returns 409 mfa_already_verified when a verified secret already exists', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    totpReq('POST', '/me/totp', $auth['jwt'])->assertOk();
    $row = TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->first();
    $code = (new Google2FA)->getCurrentOtp($row->secret);
    totpReq('POST', '/me/totp/verify', $auth['jwt'], ['code' => $code])->assertOk();

    $r = totpReq('POST', '/me/totp', $auth['jwt']);

    $r->assertStatus(409);
    expect($r->json('errors.0.code'))->toBe('mfa_already_verified');
});

it('POST /me/totp returns 422 mfa_not_enabled when totp.enabled is false', function (): void {
    $f = MeTestSupport::bootEnv([
        'multi_factor' => ['totp' => ['enabled' => false], 'backup_codes' => ['enabled' => true, 'default_count' => 10]],
    ]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = totpReq('POST', '/me/totp', $auth['jwt']);

    $r->assertStatus(422);
    expect($r->json('errors.0.code'))->toBe('mfa_not_enabled');
});

it('DELETE /me/totp removes the row, flips totp_enabled, and stamps mfa_disabled_at when no other factor remains', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    totpReq('POST', '/me/totp', $auth['jwt'])->assertOk();
    $row = TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->first();
    $code = (new Google2FA)->getCurrentOtp($row->secret);
    totpReq('POST', '/me/totp/verify', $auth['jwt'], ['code' => $code])->assertOk();

    totpReq('DELETE', '/me/totp', $auth['jwt'])->assertOk();

    $count = TotpSecret::query()->withoutGlobalScopes()->where('user_id', $auth['user']->id)->count();
    expect($count)->toBe(0);
    $user = User::query()->withoutGlobalScopes()->where('id', $auth['user']->id)->first();
    expect($user->totp_enabled)->toBeFalse();
    expect($user->two_factor_enabled)->toBeFalse();
    expect($user->mfa_disabled_at)->not->toBeNull();
});

it('GET /me/totp returns 404 totp_not_found when nothing is enrolled', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = totpReq('GET', '/me/totp', $auth['jwt']);

    $r->assertStatus(404);
    expect($r->json('errors.0.code'))->toBe('totp_not_found');
});
