<?php

declare(strict_types=1);

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

function mePasskeyReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

it('GET /v1/me/passkeys returns the empty list for a fresh user', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = mePasskeyReq('GET', '/me/passkeys', $auth['jwt']);
    $r->assertOk()
        ->assertJsonPath('total_count', 0)
        ->assertJsonPath('data', []);
});

it('GET /v1/me/passkeys returns the user verified passkeys', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $verified = Passkey::factory()->create([
        'user_id' => $auth['user']->id,
        'nickname' => 'YubiKey 5C',
    ]);
    Passkey::factory()->unverified()->create(['user_id' => $auth['user']->id]);

    $r = mePasskeyReq('GET', '/me/passkeys', $auth['jwt']);

    $r->assertOk()
        ->assertJsonPath('total_count', 2)
        ->assertJsonPath('data.0.object', 'passkey');
    $ids = array_column($r->json('data'), 'id');
    expect($ids)->toContain($verified->id);
});

it('POST /v1/me/passkeys/begin-registration returns a Challenge with creation_options', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = mePasskeyReq('POST', '/me/passkeys/begin-registration', $auth['jwt'], ['nickname' => 'My laptop']);

    $r->assertCreated();
    $r->assertJsonPath('object', 'challenge');
    $r->assertJsonPath('strategy', 'passkey');
    $r->assertJsonPath('status', 'pending');
    expect($r->json('creation_options.rp.id'))->toBe('acme.authn.local');
    expect($r->json('creation_options.pub_key_cred_params'))->not->toBeEmpty();
    expect($r->json('creation_options.exclude_credentials'))->toBe([]);
});

it('POST /v1/me/passkeys/begin-registration 422s when passkey strategy is disabled', function (): void {
    $f = MeTestSupport::bootEnv(['authentication_strategies' => ['passkey' => ['enabled' => false]]]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = mePasskeyReq('POST', '/me/passkeys/begin-registration', $auth['jwt']);

    $r->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'passkey_not_supported');
});

it('POST /v1/me/passkeys/begin-registration 422s when nickname exceeds 100 chars', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = mePasskeyReq('POST', '/me/passkeys/begin-registration', $auth['jwt'], [
        'nickname' => str_repeat('a', 101),
    ]);

    $r->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'form_param_format_invalid');
});

it('POST /v1/me/passkeys/complete-registration/{id} 422s on malformed attestation', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $begin = mePasskeyReq('POST', '/me/passkeys/begin-registration', $auth['jwt']);
    $challengeId = $begin->json('id');

    $r = mePasskeyReq('POST', "/me/passkeys/complete-registration/{$challengeId}", $auth['jwt'], [
        'attestation' => ['id' => 'aGVsbG8', 'response' => ['attestationObject' => '!!', 'clientDataJSON' => '!!']],
    ]);

    $r->assertStatus(422);
    expect($r->json('errors.0.code'))->toBe('passkey_attestation_invalid');
});

it('POST /v1/me/passkeys/complete-registration/{id} 404 on unknown challenge', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = mePasskeyReq('POST', '/me/passkeys/complete-registration/chal_01HQX0000000000000000000', $auth['jwt'], [
        'attestation' => ['id' => '', 'response' => []],
    ]);

    $r->assertStatus(404);
});

it('PATCH /v1/me/passkeys/{id} renames the row', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $passkey = Passkey::factory()->create([
        'user_id' => $auth['user']->id,
        'nickname' => 'Old name',
    ]);

    $r = mePasskeyReq('PATCH', "/me/passkeys/{$passkey->id}", $auth['jwt'], ['nickname' => 'Work YubiKey']);

    $r->assertOk();
    expect($r->json('response.nickname'))->toBe('Work YubiKey');
    expect($passkey->fresh()->nickname)->toBe('Work YubiKey');
});

it('DELETE /v1/me/passkeys/{id} soft-removes the row', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $passkey = Passkey::factory()->create(['user_id' => $auth['user']->id]);

    $r = mePasskeyReq('DELETE', "/me/passkeys/{$passkey->id}", $auth['jwt']);

    $r->assertOk();
    expect(Passkey::query()->find($passkey->id))->toBeNull();
    expect(Passkey::withTrashed()->find($passkey->id)?->removed_at)->not->toBeNull();
});

it('PATCH /v1/me/passkeys/{id} 404 when the row belongs to another user', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $other = User::create(['environment_id' => $f['env']->id]);
    $passkey = Passkey::factory()->create(['user_id' => $other->id]);

    $r = mePasskeyReq('PATCH', "/me/passkeys/{$passkey->id}", $auth['jwt'], ['nickname' => 'Try']);

    $r->assertStatus(404);
});
