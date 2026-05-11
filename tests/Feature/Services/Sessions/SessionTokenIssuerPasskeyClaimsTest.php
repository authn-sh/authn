<?php

declare(strict_types=1);

use App\Models\Passkey;
use App\Models\SignInAttempt;
use App\Models\Verification;
use App\Services\Sessions\SessionTokenIssuer;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Tests\Feature\Http\Me\MeTestSupport;

function decodePasskeyClaims(string $jwt): array
{
    $config = Configuration::forSymmetricSigner(
        new Sha256,
        InMemory::plainText(str_repeat('x', 64)),
    );

    return $config->parser()->parse($jwt)->claims()->all();
}

it('emits pkv: false + pkc: 0 when the user has no passkeys and no passkey SignInAttempt', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodePasskeyClaims($minted['jwt']);

    expect($claims)->toHaveKey('pkv');
    expect($claims['pkv'])->toBeFalse();
    expect($claims)->toHaveKey('pkc');
    expect($claims['pkc'])->toBe(0);
});

it('emits pkc as a snapshot of the user verified passkey count', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    Passkey::factory()->create(['user_id' => $auth['user']->id, 'verified_at' => now()]);
    Passkey::factory()->create(['user_id' => $auth['user']->id, 'verified_at' => now()]);
    Passkey::factory()->unverified()->create(['user_id' => $auth['user']->id]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodePasskeyClaims($minted['jwt']);

    expect($claims['pkc'])->toBe(2);
});

it('emits pkv: true when the SignInAttempt that produced this session has a verified passkey Verification', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    // Wire the SignInAttempt that "produced" this session and attach a
    // verified passkey Verification to it.
    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $auth['client']->id,
        'status' => 'complete',
        'identifier' => 'alice@example.com',
        'created_session_id' => $auth['session']->id,
    ]);
    Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => Verification::STRATEGY_PASSKEY,
        'status' => Verification::STATUS_VERIFIED,
        'expire_at' => now()->addMinutes(10),
        'verified_at' => now(),
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodePasskeyClaims($minted['jwt']);

    expect($claims['pkv'])->toBeTrue();
});

it('emits pkv: false when the SignInAttempt was resolved via a non-passkey strategy', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $auth['client']->id,
        'status' => 'complete',
        'identifier' => 'alice@example.com',
        'created_session_id' => $auth['session']->id,
    ]);
    Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => Verification::STRATEGY_PASSWORD,
        'status' => Verification::STATUS_VERIFIED,
        'expire_at' => now()->addMinutes(10),
        'verified_at' => now(),
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodePasskeyClaims($minted['jwt']);

    expect($claims['pkv'])->toBeFalse();
});

it('emits pkv: false when the SignInAttempt has an unverified passkey Verification', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $auth['client']->id,
        'status' => 'pending',
        'identifier' => 'alice@example.com',
        'created_session_id' => $auth['session']->id,
    ]);
    Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => Verification::STRATEGY_PASSKEY,
        'status' => Verification::STATUS_UNVERIFIED,
        'expire_at' => now()->addMinutes(10),
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodePasskeyClaims($minted['jwt']);

    expect($claims['pkv'])->toBeFalse();
});
