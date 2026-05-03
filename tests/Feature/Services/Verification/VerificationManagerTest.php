<?php

declare(strict_types=1);

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Verification;
use App\Services\Verification\VerificationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function vmFixture(): array
{
    $project = Project::create(['name' => 'P', 'slug' => 'p']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'env',
        'frontend_api_host' => 'env.authn.local',
    ]);
    $user = User::create(['environment_id' => $env->id]);
    $email = EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'alice@example.com',
    ]);

    return ['env' => $env, 'email' => $email];
}

it('starts a fresh verification with status=unverified and 0 attempts', function (): void {
    $f = vmFixture();
    $manager = app(VerificationManager::class);

    $v = $manager->start($f['email'], Verification::STRATEGY_EMAIL_CODE, 600);

    expect($v->id)->toStartWith('ver_');
    expect($v->status)->toBe(Verification::STATUS_UNVERIFIED);
    expect($v->attempts)->toBe(0);
    expect($v->strategy)->toBe('email_code');
    expect($v->verifiable_id)->toBe($f['email']->id);
    expect($v->expire_at->getTimestamp())->toBeGreaterThan(now()->addSeconds(595)->getTimestamp());
});

it('refuses strategies not enabled in v0.1', function (): void {
    $f = vmFixture();
    $manager = app(VerificationManager::class);

    expect(fn () => $manager->start($f['email'], 'oauth_google', 600))
        ->toThrow(InvalidArgumentException::class, 'is not enabled in v0.1');
});

it('mints a numeric code whose hash is what we store', function (): void {
    $f = vmFixture();
    $manager = app(VerificationManager::class);

    $v = $manager->start($f['email'], 'email_code', 600);
    $code = $manager->mintNumericCode($v, 'email_code', 600);

    expect($code)->toMatch('/^[0-9]{6}$/');
    expect($v->codes()->count())->toBe(1);

    $stored = $v->codes()->first();
    expect($stored->code_hash)->toBe(hash('sha256', $code));
    expect($stored->code_hash)->not->toBe($code);
});

it('flips status to verified on the correct code', function (): void {
    $f = vmFixture();
    $manager = app(VerificationManager::class);

    $v = $manager->start($f['email'], 'email_code', 600);
    $code = $manager->mintNumericCode($v, 'email_code', 600);

    expect($manager->attempt($v, $code))->toBeTrue();
    $v->refresh();
    expect($v->status)->toBe(Verification::STATUS_VERIFIED);
    expect($v->verified_at)->not->toBeNull();
});

it('flips status to failed after max_attempts wrong codes', function (): void {
    $f = vmFixture();
    $manager = app(VerificationManager::class);

    $v = $manager->start($f['email'], 'email_code', 600);
    $manager->mintNumericCode($v, 'email_code', 600);

    for ($i = 1; $i <= 4; $i++) {
        expect($manager->attempt($v, '000000'))->toBeFalse();
        $v->refresh();
        expect($v->status)->toBe(Verification::STATUS_UNVERIFIED);
        expect($v->attempts)->toBe($i);
    }

    expect($manager->attempt($v, '000000'))->toBeFalse();
    $v->refresh();
    expect($v->status)->toBe(Verification::STATUS_FAILED);
    expect($v->error_code)->toBe('verification_failed');
});

it('refuses to redeem an expired verification', function (): void {
    $f = vmFixture();
    $manager = app(VerificationManager::class);

    $v = $manager->start($f['email'], 'email_code', 600);
    $code = $manager->mintNumericCode($v, 'email_code', 600);

    $v->forceFill(['expire_at' => now()->subSecond()])->save();

    expect($manager->attempt($v, $code))->toBeFalse();
    $v->refresh();
    expect($v->status)->toBe(Verification::STATUS_EXPIRED);
});

it('marks a code as consumed once redeemed', function (): void {
    $f = vmFixture();
    $manager = app(VerificationManager::class);

    $v = $manager->start($f['email'], 'email_code', 600);
    $code = $manager->mintNumericCode($v, 'email_code', 600);
    $manager->attempt($v, $code);

    $stored = $v->codes()->first();
    expect($stored->consumed_at)->not->toBeNull();

    // A second attempt with the same code finds no usable code → expired branch.
    expect($manager->attempt($v, $code))->toBeFalse();
});

it('writes verifications under the owner environment_id', function (): void {
    $f = vmFixture();
    $manager = app(VerificationManager::class);

    $v = $manager->start($f['email'], 'email_code', 600);

    expect($v->environment_id)->toBe($f['env']->id);
});
