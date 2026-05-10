<?php

declare(strict_types=1);

use App\Auth\Strategies\PhoneCodeStrategy;
use App\Http\Resources\SignInResource;
use App\Jobs\Sms\SendVerificationSms;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\Project;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Queue;

function makeClientForPhoneCode(Environment $env): string
{
    return Client::create(['environment_id' => $env->id])->id;
}

function envForPhoneCode(): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-pcs']);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'pcs',
        'routing_label' => 'pcs',
    ]);
}

function makeUserWithVerifiedPhone(Environment $env, string $phoneNumber, bool $reservedForSecondFactor = true): array
{
    $user = User::create(['environment_id' => $env->id]);
    EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'u-'.uniqid().'@example.com',
        'is_primary' => true,
    ]);
    $phone = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => $phoneNumber,
        'reserved_for_second_factor' => $reservedForSecondFactor,
    ]);
    $phone->markVerified();

    return ['user' => $user, 'phone' => $phone->fresh()];
}

it('prepare() dispatches SendVerificationSms and returns the verification', function (): void {
    Queue::fake();
    $env = envForPhoneCode();
    $f = makeUserWithVerifiedPhone($env, '+15551230001');
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => makeClientForPhoneCode($env),
        'identifier' => $f['user']->emailAddresses()->first()->email_address,
        'status' => SignInAttempt::STATUS_NEEDS_SECOND_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);

    $result = app(PhoneCodeStrategy::class)->prepare($attempt, []);

    expect($result->success)->toBeTrue();
    expect($result->verification)->not->toBeNull();
    expect($result->verification->strategy)->toBe(Verification::STRATEGY_PHONE_CODE);
    Queue::assertPushed(SendVerificationSms::class);
});

it('attempt() succeeds with the right code and verifies the verification', function (): void {
    $env = envForPhoneCode();
    $f = makeUserWithVerifiedPhone($env, '+15551230002');
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => makeClientForPhoneCode($env),
        'identifier' => $f['user']->emailAddresses()->first()->email_address,
        'status' => SignInAttempt::STATUS_NEEDS_SECOND_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $f['phone']->getMorphClass(),
        'verifiable_id' => $f['phone']->id,
        'strategy' => Verification::STRATEGY_PHONE_CODE,
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addMinutes(10),
    ]);
    $known = '424242';
    VerificationCode::query()->create([
        'verification_id' => $verification->id,
        'code_hash' => hash('sha256', $known),
        'purpose' => PhoneCodeStrategy::PURPOSE,
        'expires_at' => now()->addMinutes(10),
    ]);

    $result = app(PhoneCodeStrategy::class)->attempt($attempt, ['code' => $known, 'verification' => $verification]);

    expect($result->success)->toBeTrue();
    expect($result->user?->id)->toBe($f['user']->id);
    expect($verification->fresh()->status)->toBe(Verification::STATUS_VERIFIED);
});

it('attempt() returns form_code_incorrect on the wrong code', function (): void {
    $env = envForPhoneCode();
    $f = makeUserWithVerifiedPhone($env, '+15551230003');
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => makeClientForPhoneCode($env),
        'identifier' => $f['user']->emailAddresses()->first()->email_address,
        'status' => SignInAttempt::STATUS_NEEDS_SECOND_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $f['phone']->getMorphClass(),
        'verifiable_id' => $f['phone']->id,
        'strategy' => Verification::STRATEGY_PHONE_CODE,
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addMinutes(10),
    ]);
    VerificationCode::query()->create([
        'verification_id' => $verification->id,
        'code_hash' => hash('sha256', 'right-code'),
        'purpose' => PhoneCodeStrategy::PURPOSE,
        'expires_at' => now()->addMinutes(10),
    ]);

    $result = app(PhoneCodeStrategy::class)->attempt($attempt, ['code' => 'wrong', 'verification' => $verification]);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('form_code_incorrect');
});

it('prepare() falls back to the user-resolved reserved phone when phone_number_id is omitted', function (): void {
    Queue::fake();
    $env = envForPhoneCode();
    $f = makeUserWithVerifiedPhone($env, '+15551230004');
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => makeClientForPhoneCode($env),
        'identifier' => $f['user']->emailAddresses()->first()->email_address,
        'status' => SignInAttempt::STATUS_NEEDS_SECOND_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);

    $result = app(PhoneCodeStrategy::class)->prepare($attempt, []);

    expect($result->success)->toBeTrue();
    expect($result->verification?->verifiable_id)->toBe($f['phone']->id);
});

it('prepare() returns phone_not_found when the user has no reserved-for-second-factor phone', function (): void {
    $env = envForPhoneCode();
    $f = makeUserWithVerifiedPhone($env, '+15551230005', reservedForSecondFactor: false);
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => makeClientForPhoneCode($env),
        'identifier' => $f['user']->emailAddresses()->first()->email_address,
        'status' => SignInAttempt::STATUS_NEEDS_SECOND_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);

    $result = app(PhoneCodeStrategy::class)->prepare($attempt, []);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('phone_not_found');
});

it('prepare() with phone_number_id targets a specific row (sign-up flow)', function (): void {
    Queue::fake();
    $env = envForPhoneCode();
    $user = User::create(['environment_id' => $env->id]);
    $phone = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15551230006',
    ]);
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => makeClientForPhoneCode($env),
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);

    $result = app(PhoneCodeStrategy::class)->prepare($attempt, ['phone_number_id' => $phone->id]);

    expect($result->success)->toBeTrue();
    expect($result->verification?->verifiable_id)->toBe($phone->id);
});

it('attempt() flips an unverified phone to verified on first-factor success', function (): void {
    $env = envForPhoneCode();
    $user = User::create(['environment_id' => $env->id]);
    $phone = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15551230007',
    ]);
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => makeClientForPhoneCode($env),
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $phone->getMorphClass(),
        'verifiable_id' => $phone->id,
        'strategy' => Verification::STRATEGY_PHONE_CODE,
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addMinutes(10),
    ]);
    $known = '424242';
    VerificationCode::query()->create([
        'verification_id' => $verification->id,
        'code_hash' => hash('sha256', $known),
        'purpose' => PhoneCodeStrategy::PURPOSE,
        'expires_at' => now()->addMinutes(10),
    ]);

    $result = app(PhoneCodeStrategy::class)->attempt($attempt, ['code' => $known, 'verification' => $verification]);

    expect($result->success)->toBeTrue();
    expect($phone->fresh()->verified_at)->not->toBeNull();
});

it('SignInResource exposes phone_code as a second factor when the user has a reserved phone', function (): void {
    $env = envForPhoneCode();
    $f = makeUserWithVerifiedPhone($env, '+15551230008');
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => makeClientForPhoneCode($env),
        'identifier' => $f['user']->emailAddresses()->first()->email_address,
        'status' => SignInAttempt::STATUS_NEEDS_SECOND_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);

    $shape = SignInResource::from($attempt);

    expect($shape['supported_strategies'])->toContain('phone_code');
});
