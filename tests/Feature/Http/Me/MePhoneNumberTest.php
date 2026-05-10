<?php

declare(strict_types=1);

use App\Models\PhoneNumber;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

function mePhoneReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

it('GET /v1/me/phone-numbers returns the empty list for a fresh user', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = mePhoneReq('GET', '/me/phone-numbers', $auth['jwt']);
    $r->assertOk()
        ->assertJsonPath('total_count', 0)
        ->assertJsonPath('data', []);
});

it('POST /v1/me/phone-numbers creates an unverified row', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = mePhoneReq('POST', '/me/phone-numbers', $auth['jwt'], ['phone_number' => '+15555550100']);
    $r->assertCreated()
        ->assertJsonPath('response.object', 'phone_number')
        ->assertJsonPath('response.phone_number', '+15555550100')
        ->assertJsonPath('response.verified', false)
        ->assertJsonPath('response.is_primary', false);
});

it('POST /v1/me/phone-numbers 422s on non-E.164 input', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = mePhoneReq('POST', '/me/phone-numbers', $auth['jwt'], ['phone_number' => '555-555-0100']);
    $r->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'form_param_format_invalid');
});

it('POST /v1/me/phone-numbers 422s on duplicate within env', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    mePhoneReq('POST', '/me/phone-numbers', $auth['jwt'], ['phone_number' => '+15555550100'])->assertCreated();

    $r = mePhoneReq('POST', '/me/phone-numbers', $auth['jwt'], ['phone_number' => '+15555550100']);
    $r->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'form_identifier_exists');
});

it('GET /v1/me/phone-numbers/{id} returns one row', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $row = PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550101',
    ]);

    mePhoneReq('GET', '/me/phone-numbers/'.$row->id, $auth['jwt'])
        ->assertOk()
        ->assertJsonPath('id', $row->id)
        ->assertJsonPath('phone_number', '+15555550101');
});

it('PATCH /v1/me/phone-numbers/{id} 422s when promoting an unverified phone to primary', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $row = PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550102',
    ]);

    mePhoneReq('PATCH', '/me/phone-numbers/'.$row->id, $auth['jwt'], ['is_primary' => true])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'phone_not_verified');
});

it('PATCH /v1/me/phone-numbers/{id} promotes a verified row + flips reserved_for_second_factor', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $row = PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550103',
    ]);
    $row->markVerified();

    mePhoneReq('PATCH', '/me/phone-numbers/'.$row->id, $auth['jwt'], [
        'is_primary' => true,
        'reserved_for_second_factor' => true,
    ])->assertOk()
        ->assertJsonPath('response.is_primary', true)
        ->assertJsonPath('response.reserved_for_second_factor', true);
});

it('DELETE /v1/me/phone-numbers/{id} 409s when reserved_for_second_factor is on', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $row = PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550104',
        'reserved_for_second_factor' => true,
    ]);

    mePhoneReq('DELETE', '/me/phone-numbers/'.$row->id, $auth['jwt'])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'phone_reserved_for_second_factor');
});

it('DELETE /v1/me/phone-numbers/{id} hard-deletes when not reserved', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $row = PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550105',
    ]);

    mePhoneReq('DELETE', '/me/phone-numbers/'.$row->id, $auth['jwt'])
        ->assertStatus(204);

    expect(PhoneNumber::query()->withoutGlobalScopes()->where('id', $row->id)->exists())->toBeFalse();
});

it('GET /v1/me/phone-numbers/{id} 404 when not on this user', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    mePhoneReq('GET', '/me/phone-numbers/phn_nope', $auth['jwt'])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'phone_not_found');
});

it('UserResource.phone_numbers reflects the row immediately after create', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    mePhoneReq('POST', '/me/phone-numbers', $auth['jwt'], ['phone_number' => '+15555550106'])->assertCreated();

    $r = mePhoneReq('GET', '/me', $auth['jwt']);
    $r->assertOk();
    $phones = $r->json('phone_numbers');
    expect(count($phones))->toBe(1);
    expect($phones[0]['phone_number'])->toBe('+15555550106');
});
