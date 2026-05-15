<?php

declare(strict_types=1);

use App\Models\EmailAddress;
use App\Models\Session;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

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
        ->assertJsonPath('private_metadata', []);
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
        ->assertJsonPath('response.first_name', 'Alicia')
        ->assertJsonPath('response.last_name', 'Smith-Jones')
        ->assertJsonPath('client.object', 'client');
});

it('POST /v1/me/email-addresses creates an unverified row; verifies via Challenge sub-resource', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $created = meReq('POST', '/me/email-addresses', $auth['jwt'], ['email_address' => 'alt@example.com']);
    $created->assertOk()
        ->assertJsonPath('response.email_address', 'alt@example.com')
        ->assertJsonPath('response.verified', false)
        ->assertJsonPath('response.current_challenge_id', null);
    $eid = $created->json('response.id');

    $challenge = meReq('POST', "/me/email-addresses/{$eid}/challenges", $auth['jwt'], [
        'strategy' => 'email_code',
    ]);
    $challenge->assertOk()
        ->assertJsonPath('response.object', 'challenge')
        ->assertJsonPath('response.strategy', 'email_code')
        ->assertJsonPath('response.status', 'pending')
        ->assertJsonPath('response.email_address_id', $eid);
    $cid = $challenge->json('response.id');

    // Stamp a known code on the verification row.
    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    $codeRow = VerificationCode::query()->where('verification_id', $verification->id)->latest('id')->first();
    $known = '424242';
    $codeRow->forceFill(['code_hash' => hash('sha256', $known)])->save();

    meReq('POST', "/me/email-addresses/{$eid}/challenges/{$cid}/answer", $auth['jwt'], [
        'code' => $known,
    ])->assertOk()
        ->assertJsonPath('response.object', 'challenge')
        ->assertJsonPath('response.status', 'verified');

    expect(EmailAddress::query()->withoutGlobalScopes()->where('id', $eid)->first()->isVerified())->toBeTrue();
});

it('POST /v1/me/change-password rotates the hash; wrong current returns form_password_incorrect', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    meReq('POST', '/me/change-password', $auth['jwt'], [
        'current_password' => 'super-secret-password',
        'new_password' => 'brand-new-password-9000',
    ])->assertOk()->assertJsonPath('response.success', true);

    expect(User::query()->withoutGlobalScopes()->where('id', $auth['user']->id)->first()->checkPassword('brand-new-password-9000'))->toBeTrue();

    // Wrong current → 422 form_password_incorrect.
    meReq('POST', '/me/change-password', $auth['jwt'], [
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
    meReq('POST', '/me/change-password', $auth['jwt'], [
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

it('DELETE /v1/me/password clears the password hash when env permits it', function (): void {
    $f = MeTestSupport::bootEnv([
        'attributes' => ['password' => ['enabled' => true, 'required' => false]],
    ]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    meReq('DELETE', '/me/password', $auth['jwt'], [
        'current_password' => 'super-secret-password',
    ])->assertOk()
        ->assertJsonPath('response.password_enabled', false);

    expect(User::query()->withoutGlobalScopes()->where('id', $auth['user']->id)->first()->password_hash)->toBeNull();
});

it('DELETE /v1/me/password returns 422 form_password_incorrect on wrong current password', function (): void {
    $f = MeTestSupport::bootEnv([
        'attributes' => ['password' => ['enabled' => true, 'required' => false]],
    ]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    meReq('DELETE', '/me/password', $auth['jwt'], [
        'current_password' => 'wrong',
    ])->assertStatus(422)->assertJsonPath('errors.0.code', 'form_password_incorrect');
});

it('DELETE /v1/me/password returns 422 when the environment requires a password', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    meReq('DELETE', '/me/password', $auth['jwt'], [
        'current_password' => 'super-secret-password',
    ])->assertStatus(422)->assertJsonPath('errors.0.code', 'form_password_validation_failed');
});

it('DELETE /v1/me/password returns 422 when the user has no password set', function (): void {
    $f = MeTestSupport::bootEnv([
        'attributes' => ['password' => ['enabled' => true, 'required' => false]],
    ]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $auth['user']->forceFill(['password_hash' => null])->save();

    meReq('DELETE', '/me/password', $auth['jwt'], [
        'current_password' => 'super-secret-password',
    ])->assertStatus(422)->assertJsonPath('errors.0.code', 'form_password_validation_failed');
});

it('DELETE /v1/me/password is forbidden for impersonation sessions', function (): void {
    $f = MeTestSupport::bootEnv([
        'attributes' => ['password' => ['enabled' => true, 'required' => false]],
    ]);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env'], [
        'actor' => ['iss' => 'https://actor.example.com', 'sub' => 'admin', 'sid' => 'sess_admin'],
    ]);

    meReq('DELETE', '/me/password', $auth['jwt'], [
        'current_password' => 'super-secret-password',
    ])->assertStatus(403)->assertJsonPath('errors.0.code', 'actor_session_forbidden');
});

it('POST /v1/me/profile-image stores the image and updates image_url', function (): void {
    Storage::fake('s3', ['url' => 'https://images.example.com']);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $file = UploadedFile::fake()->image('avatar.png', 64, 64);

    test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$auth['jwt'],
            'Accept' => 'application/json',
        ])
        ->post('https://acme.authn.local/v1/me/profile-image', ['file' => $file])
        ->assertOk()
        ->assertJsonPath('response.has_image', true);

    expect(User::query()->withoutGlobalScopes()->where('id', $auth['user']->id)->first()->image_url)->not->toBeNull();
});

it('POST /v1/me/profile-image returns 415 for an unsupported MIME', function (): void {
    Storage::fake('s3', ['url' => 'https://images.example.com']);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $file = UploadedFile::fake()->create('avatar.txt', 4, 'text/plain');

    test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$auth['jwt'],
            'Accept' => 'application/json',
        ])
        ->post('https://acme.authn.local/v1/me/profile-image', ['file' => $file])
        ->assertStatus(415)
        ->assertJsonPath('errors.0.code', 'form_param_format_invalid');
});

it('POST /v1/me/profile-image is forbidden for impersonation sessions', function (): void {
    Storage::fake('s3', ['url' => 'https://images.example.com']);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env'], [
        'actor' => ['iss' => 'https://actor.example.com', 'sub' => 'admin', 'sid' => 'sess_admin'],
    ]);

    $file = UploadedFile::fake()->image('avatar.png', 64, 64);

    test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$auth['jwt'],
            'Accept' => 'application/json',
        ])
        ->post('https://acme.authn.local/v1/me/profile-image', ['file' => $file])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'actor_session_forbidden');
});

it('DELETE /v1/me/profile-image clears image_url and is idempotent', function (): void {
    Storage::fake('s3', ['url' => 'https://images.example.com']);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $auth['user']->forceFill(['image_url' => 'https://example.com/avatar.png', 'has_image' => true])->save();

    meReq('DELETE', '/me/profile-image', $auth['jwt'])
        ->assertOk()
        ->assertJsonPath('response.has_image', false);

    expect(User::query()->withoutGlobalScopes()->where('id', $auth['user']->id)->first()->image_url)->toBeNull();

    // Second call is idempotent — no error.
    meReq('DELETE', '/me/profile-image', $auth['jwt'])->assertOk();
});

it('DELETE /v1/me/profile-image is forbidden for impersonation sessions', function (): void {
    Storage::fake('s3', ['url' => 'https://images.example.com']);
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env'], [
        'actor' => ['iss' => 'https://actor.example.com', 'sub' => 'admin', 'sid' => 'sess_admin'],
    ]);

    meReq('DELETE', '/me/profile-image', $auth['jwt'])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'actor_session_forbidden');
});
