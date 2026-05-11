<?php

declare(strict_types=1);

use App\Jobs\Sms\SendVerificationSms;
use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\Session;
use App\Models\TotpSecret;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\SignIn\SignInTestSupport;

/**
 * AU-16: end-to-end second-factor sign-in via SMS-OTP. Mirrors
 * SecondFactorTest's TOTP cases but for phone_code (AU-9). Pairs the
 * verified+reserved phone with a TOTP enrolment so the existing pivot
 * (totp/backup_code only, see ChallengeController::userHasEnrolledSecondFactor)
 * fires; phone_code then appears alongside TOTP in supported_strategies.
 */
function au16PhoneSignInPost(array $f, array $body, ?string $cookie = null): TestResponse
{
    $req = test()->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']]);
    if ($cookie !== null) {
        $req = $req->withUnencryptedCookie('__client', $cookie);
    }

    return $req->postJson('https://acme.authn.local/v1/client/sign-ins', $body);
}

function au16PhoneSignInChallenge(string $sid, array $f, array $body, string $cookie): TestResponse
{
    return test()->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->withUnencryptedCookie('__client', $cookie)
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", $body);
}

function au16PhoneSignInAnswer(string $sid, string $cid, array $f, array $body, string $cookie): TestResponse
{
    return test()->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->withUnencryptedCookie('__client', $cookie)
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges/{$cid}/answer", $body);
}

function au16BootUserWithPhoneOnly(array $f): array
{
    $bundle = SignInTestSupport::makeUser($f['env']);
    PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'phone_number' => '+15555550199',
        'verified_at' => now(),
        'reserved_for_second_factor' => true,
        'default_second_factor' => true,
        'is_primary' => false,
    ]);
    $bundle['user']->forceFill(['two_factor_enabled' => true])->save();

    return $bundle;
}

function au16BootUserWithTotpAndPhone(array $f): array
{
    $bundle = SignInTestSupport::makeUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);
    PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $bundle['user']->id,
        'phone_number' => '+15555550199',
        'verified_at' => now(),
        'reserved_for_second_factor' => true,
        'default_second_factor' => true,
        'is_primary' => false,
    ]);
    $bundle['user']->forceFill(['totp_enabled' => true, 'two_factor_enabled' => true])->save();

    return $bundle;
}

it('pivots to needs_second_factor when the user only has a verified+reserved phone (no TOTP, no backup codes)', function (): void {
    $f = SignInTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    au16BootUserWithPhoneOnly($f);

    $r = au16PhoneSignInPost($f, [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ]);

    $r->assertOk()->assertJsonPath('response.status', 'needs_second_factor');
    expect($r->json('response.supported_strategies'))->toContain('phone_code')
        ->and($r->json('response.supported_strategies'))->not->toContain('totp')
        ->and($r->json('response.supported_strategies'))->not->toContain('backup_code');
});

it('phone_code is offered alongside totp once a verified+reserved phone is enrolled', function (): void {
    $f = SignInTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    au16BootUserWithTotpAndPhone($f);

    $r = au16PhoneSignInPost($f, [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ]);

    $r->assertOk()
        ->assertJsonPath('response.status', 'needs_second_factor');
    expect($r->json('response.supported_strategies'))->toContain('phone_code');
    expect($r->json('response.supported_strategies'))->toContain('totp');
});

it('completes second-factor sign-in via phone_code when the SMS code is correct', function (): void {
    Queue::fake([SendVerificationSms::class]);
    $f = SignInTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    $bundle = au16BootUserWithTotpAndPhone($f);

    $cb = SignInTestSupport::clientWithCookie($f['env']);
    $first = au16PhoneSignInPost($f, [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ], $cb['cookie']);
    $sid = $first->json('response.id');
    expect($sid)->toStartWith('sia_');

    $prepare = au16PhoneSignInChallenge($sid, $f, ['strategy' => 'phone_code'], $cb['cookie']);
    $prepare->assertOk();
    $cid = $prepare->json('response.id');

    $code = null;
    Queue::assertPushedOn('sms', SendVerificationSms::class, function (SendVerificationSms $job) use (&$code): bool {
        $code = $job->code;

        return true;
    });
    expect($code)->not->toBeNull()->and(strlen((string) $code))->toBe(6);

    $answer = au16PhoneSignInAnswer($sid, $cid, $f, ['code' => (string) $code], $cb['cookie']);
    $answer->assertOk();

    expect(Session::query()->withoutGlobalScopes()
        ->where('user_id', $bundle['user']->id)
        ->where('status', Session::STATUS_ACTIVE)
        ->exists())->toBeTrue();
});

it('rejects a wrong phone_code with form_code_incorrect and does not mint a session', function (): void {
    Queue::fake([SendVerificationSms::class]);
    $f = SignInTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    $bundle = au16BootUserWithTotpAndPhone($f);

    $cb = SignInTestSupport::clientWithCookie($f['env']);
    $first = au16PhoneSignInPost($f, [
        'identifier' => 'alice@example.com',
        'strategy' => 'password',
        'password' => 'super-secret-password',
    ], $cb['cookie']);
    $sid = $first->json('response.id');

    $prepare = au16PhoneSignInChallenge($sid, $f, ['strategy' => 'phone_code'], $cb['cookie']);
    $prepare->assertOk();
    $cid = $prepare->json('response.id');

    $r = au16PhoneSignInAnswer($sid, $cid, $f, ['code' => '000000'], $cb['cookie']);
    $r->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'form_code_incorrect');

    expect(Session::query()->withoutGlobalScopes()
        ->where('user_id', $bundle['user']->id)
        ->where('status', Session::STATUS_ACTIVE)
        ->exists())->toBeFalse();
});
