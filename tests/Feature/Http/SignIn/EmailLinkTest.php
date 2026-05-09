<?php

declare(strict_types=1);

use App\Jobs\Mail\SendMagicLinkEmail;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Services\Client\ClientResolver;
use App\Services\MagicLink\MagicLinkIssuer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

function fapiReq(string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders(array_merge([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
        ], $headers))
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

function setupMagicLinkUser(Environment $env, string $email = 'alice@example.com'): array
{
    $user = new User(['environment_id' => $env->id, 'first_name' => 'Alice']);
    $user->save();
    $emailRow = EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => $email,
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    return ['user' => $user, 'email' => $emailRow];
}

it('prepare_first_factor email_link issues a magic link, dispatches the email job', function (): void {
    Bus::fake([SendMagicLinkEmail::class]);
    $f = SessionsTestSupport::bootEnv();
    $u = setupMagicLinkUser($f['env']);

    // Create a sign-in attempt directly (mirrors what FAPI POST does).
    $client = Client::create(['environment_id' => $f['env']->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($client);
    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'identifier' => 'alice@example.com',
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
    ]);

    $r = test()->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => $f['origin'],
        ])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$attempt->id}/prepare_first_factor", [
            'strategy' => 'email_link',
            'email_address_id' => $u['email']->id,
        ]);
    $r->assertOk();

    $verification = Verification::query()->withoutGlobalScopes()
        ->where('verifiable_type', $attempt->getMorphClass())
        ->where('verifiable_id', $attempt->id)
        ->where('strategy', 'email_link')
        ->firstOrFail();
    expect($verification->status)->toBe('unverified');
    expect(VerificationCode::query()
        ->where('verification_id', $verification->id)
        ->where('purpose', 'magic_link')
        ->exists())->toBeTrue();

    Bus::assertDispatched(SendMagicLinkEmail::class, fn ($job) => $job->emailAddress === 'alice@example.com');
});

it('attempt_first_factor email_link returns verification_failed while link is still unredeemed', function (): void {
    Bus::fake([SendMagicLinkEmail::class]);
    $f = SessionsTestSupport::bootEnv();
    $u = setupMagicLinkUser($f['env']);
    $client = Client::create(['environment_id' => $f['env']->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($client);
    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'identifier' => 'alice@example.com',
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
    ]);

    test()->withCredentials()->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$attempt->id}/prepare_first_factor", [
            'strategy' => 'email_link',
            'email_address_id' => $u['email']->id,
        ])->assertOk();

    test()->withCredentials()->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$attempt->id}/attempt_first_factor", [
            'strategy' => 'email_link',
        ])->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'verification_failed');
});

it('end-to-end same-device magic link flow: prepare → click → attempt completes', function (): void {
    Bus::fake([SendMagicLinkEmail::class]);
    $f = SessionsTestSupport::bootEnv();
    $u = setupMagicLinkUser($f['env']);
    $client = Client::create(['environment_id' => $f['env']->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($client);
    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'identifier' => 'alice@example.com',
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
    ]);

    test()->withCredentials()->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$attempt->id}/prepare_first_factor", [
            'strategy' => 'email_link',
            'email_address_id' => $u['email']->id,
        ])->assertOk();

    // Mint a magic link directly (simulates the email click). The issuer
    // returns the click URL so we extract the JWT from it.
    $verification = Verification::query()->withoutGlobalScopes()
        ->where('verifiable_type', $attempt->getMorphClass())
        ->where('verifiable_id', $attempt->id)
        ->where('strategy', 'email_link')
        ->firstOrFail();
    // Re-mint a fresh JWT so the test exercises the click path. The
    // VerificationCode persisted on prepare is what the verifier matches
    // against — we use that one.
    $code = VerificationCode::query()->where('verification_id', $verification->id)->firstOrFail();
    expect($code->consumed_at)->toBeNull();

    // Re-issue using the issuer to get a fresh JWT we have plaintext for.
    $issued = app(MagicLinkIssuer::class)->issue($verification);

    // Click the link.
    $click = test()->withHeaders(['Host' => 'acme.authn.local'])
        ->getJson("https://acme.authn.local/v1/client/magic_link/redeem?__authn_magic_link={$issued['jwt']}");
    $click->assertOk()->assertJsonPath('verified', true);

    // Verification flipped + Client.token_version bumped.
    expect($verification->fresh()->status)->toBe('verified');
    expect((int) Client::query()->withoutGlobalScopes()->where('id', $client->id)->firstOrFail()->token_version)->toBe(1);

    // Now poll attempt_first_factor — should complete and return a session.
    $r = test()->withCredentials()->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ins/{$attempt->id}/attempt_first_factor", [
            'strategy' => 'email_link',
        ]);
    $r->assertOk()->assertJsonPath('response.status', 'complete');
});

it('replay protection: second click on the same link returns 410 consumed', function (): void {
    Bus::fake([SendMagicLinkEmail::class]);
    $f = SessionsTestSupport::bootEnv();
    $u = setupMagicLinkUser($f['env']);
    $client = Client::create(['environment_id' => $f['env']->id]);
    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'identifier' => 'alice@example.com',
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => 'email_link',
        'status' => 'unverified',
        'attempts' => 0,
        'expire_at' => now()->addMinutes(5),
    ]);
    $issued = app(MagicLinkIssuer::class)->issue($verification);

    test()->withHeaders(['Host' => 'acme.authn.local'])
        ->getJson("https://acme.authn.local/v1/client/magic_link/redeem?__authn_magic_link={$issued['jwt']}")
        ->assertOk();

    // Second click — replay.
    test()->withHeaders(['Host' => 'acme.authn.local'])
        ->getJson("https://acme.authn.local/v1/client/magic_link/redeem?__authn_magic_link={$issued['jwt']}")
        ->assertStatus(410)
        ->assertJsonPath('errors.0.code', 'magic_link_consumed');
});

it('expired link returns 410', function (): void {
    Bus::fake([SendMagicLinkEmail::class]);
    $f = SessionsTestSupport::bootEnv();
    $u = setupMagicLinkUser($f['env']);
    $client = Client::create(['environment_id' => $f['env']->id]);
    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'identifier' => 'alice@example.com',
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => 'email_link',
        'status' => 'unverified',
        'attempts' => 0,
        'expire_at' => now()->addMinutes(5),
    ]);
    $issued = app(MagicLinkIssuer::class)->issue($verification);
    // Force-expire the persisted VerificationCode.
    VerificationCode::query()->where('verification_id', $verification->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    test()->withHeaders(['Host' => 'acme.authn.local'])
        ->getJson("https://acme.authn.local/v1/client/magic_link/redeem?__authn_magic_link={$issued['jwt']}")
        ->assertStatus(410)
        ->assertJsonPath('errors.0.code', 'magic_link_expired');
});

it('missing/invalid token returns 400', function (): void {
    SessionsTestSupport::bootEnv();

    test()->withHeaders(['Host' => 'acme.authn.local'])
        ->getJson('https://acme.authn.local/v1/client/magic_link/redeem')
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'magic_link_missing');

    test()->withHeaders(['Host' => 'acme.authn.local'])
        ->getJson('https://acme.authn.local/v1/client/magic_link/redeem?__authn_magic_link=garbage')
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'magic_link_invalid');
});

it('redirect_url query param triggers a 302 redirect', function (): void {
    Bus::fake([SendMagicLinkEmail::class]);
    $f = SessionsTestSupport::bootEnv();
    $client = Client::create(['environment_id' => $f['env']->id]);
    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'identifier' => 'alice@example.com',
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => 'email_link',
        'status' => 'unverified',
        'attempts' => 0,
        'expire_at' => now()->addMinutes(5),
    ]);
    $issued = app(MagicLinkIssuer::class)->issue($verification, 'https://app.example.com/welcome');

    test()->withHeaders(['Host' => 'acme.authn.local'])
        ->get("https://acme.authn.local/v1/client/magic_link/redeem?__authn_magic_link={$issued['jwt']}&redirect_url=https://app.example.com/welcome")
        ->assertRedirect('https://app.example.com/welcome');
});
