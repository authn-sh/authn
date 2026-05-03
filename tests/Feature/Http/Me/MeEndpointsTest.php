<?php

declare(strict_types=1);

use App\Models\EmailAddress;
use App\Models\Session;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

uses(RefreshDatabase::class);

function meReq(string $method, string $path, string $jwt, array $body = [], string $origin = 'https://app.example.com'): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => $origin,
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

it('GET /v1/me returns the user without private_metadata', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $auth['user']->forceFill(['private_metadata' => ['secret' => 'value']])->save();

    $r = meReq('GET', '/me', $auth['jwt']);
    $r->assertOk()
        ->assertJsonPath('id', $auth['user']->id)
        ->assertJsonPath('first_name', 'Alice')
        ->assertJsonPath('private_metadata', null);
});

it('PATCH /v1/me updates writable fields and ignores unknown', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = meReq('PATCH', '/me', $auth['jwt'], [
        'first_name' => 'Alicia',
        'last_name' => 'Smith-Jones',
        'something_unknown' => 'ignored',
    ]);
    $r->assertOk()
        ->assertJsonPath('first_name', 'Alicia')
        ->assertJsonPath('last_name', 'Smith-Jones');
});

it('POST /v1/me/email_addresses creates an unverified row; verifies via prepare + attempt', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $created = meReq('POST', '/me/email_addresses', $auth['jwt'], ['email_address' => 'alt@example.com']);
    $created->assertStatus(201)
        ->assertJsonPath('email_address', 'alt@example.com')
        ->assertJsonPath('verified', false);
    $eid = $created->json('id');

    meReq('POST', "/me/email_addresses/{$eid}/prepare_verification", $auth['jwt'], [
        'strategy' => 'email_code',
    ])->assertOk();

    // Stamp a known code on the verification row.
    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    $codeRow = VerificationCode::query()->where('verification_id', $verification->id)->latest('id')->first();
    $known = '424242';
    $codeRow->forceFill(['code_hash' => hash('sha256', $known)])->save();

    meReq('POST', "/me/email_addresses/{$eid}/attempt_verification", $auth['jwt'], [
        'strategy' => 'email_code',
        'code' => $known,
    ])->assertOk()->assertJsonPath('verified', true);

    expect(EmailAddress::query()->withoutGlobalScopes()->where('id', $eid)->first()->isVerified())->toBeTrue();
});

it('POST /v1/me/change_password rotates the hash; wrong current returns form_password_incorrect', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    meReq('POST', '/me/change_password', $auth['jwt'], [
        'current_password' => 'super-secret-password',
        'new_password' => 'brand-new-password-9000',
    ])->assertOk()->assertJsonPath('success', true);

    expect(User::query()->withoutGlobalScopes()->where('id', $auth['user']->id)->first()->checkPassword('brand-new-password-9000'))->toBeTrue();

    // Wrong current → 422 form_password_incorrect.
    meReq('POST', '/me/change_password', $auth['jwt'], [
        'current_password' => 'definitely-wrong',
        'new_password' => 'something-else-12345',
    ])->assertStatus(422)->assertJsonPath('errors.0.code', 'form_password_incorrect');
});

it('DELETE /v1/me returns 403 when env disables delete_self_enabled', function (): void {
    $f = MeTestSupport::bootEnv(['delete_self_enabled' => false]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $auth['user']->forceFill(['delete_self_enabled' => true])->save();

    meReq('DELETE', '/me', $auth['jwt'], [])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'delete_self_disabled');
});

it('actor sessions cannot mutate /v1/me/* but can read', function (): void {
    $f = MeTestSupport::bootEnv();
    // actor JSON marks the session as impersonation.
    $auth = MeTestSupport::makeAuthenticatedUser($f['env'], [
        'actor' => ['iss' => 'https://actor.example.com', 'sub' => 'admin', 'sid' => 'sess_admin'],
    ]);

    // Read endpoints are still allowed.
    meReq('GET', '/me', $auth['jwt'])->assertOk();

    // Mutating endpoints are forbidden.
    meReq('PATCH', '/me', $auth['jwt'], ['first_name' => 'Hacked'])
        ->assertStatus(403)->assertJsonPath('errors.0.code', 'actor_session_forbidden');
    meReq('POST', '/me/change_password', $auth['jwt'], [
        'current_password' => 'super-secret-password',
        'new_password' => 'definitely-not-good',
    ])->assertStatus(403)->assertJsonPath('errors.0.code', 'actor_session_forbidden');
});

it('banned users cannot reach /v1/me/* and their session is revoked', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $auth['user']->forceFill(['banned' => true])->save();

    meReq('GET', '/me', $auth['jwt'])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'user_banned');

    expect(Session::query()->withoutGlobalScopes()->where('id', $auth['session']->id)->first()->status)
        ->toBe('revoked');
});

it('GET /v1/me/sessions lists the user sessions', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $r = meReq('GET', '/me/sessions', $auth['jwt']);
    $r->assertOk()
        ->assertJsonPath('total_count', 1)
        ->assertJsonPath('data.0.id', $auth['session']->id);
});
