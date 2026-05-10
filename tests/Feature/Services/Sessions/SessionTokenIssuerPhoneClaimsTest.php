<?php

declare(strict_types=1);

use App\Models\PhoneNumber;
use App\Models\TotpSecret;
use App\Services\Sessions\SessionTokenIssuer;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Tests\Feature\Http\Me\MeTestSupport;

function decodeIssuedClaims(string $jwt): array
{
    $config = Configuration::forSymmetricSigner(
        new Sha256,
        InMemory::plainText(str_repeat('x', 64)),
    );

    return $config->parser()->parse($jwt)->claims()->all();
}

it('emits pnv: false when the user has no phone numbers', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeIssuedClaims($minted['jwt']);

    expect($claims)->toHaveKey('pnv');
    expect($claims['pnv'])->toBeFalse();
});

it('emits pnv: false when the user has only an unverified phone', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550100',
        'is_primary' => false,
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeIssuedClaims($minted['jwt']);

    expect($claims['pnv'])->toBeFalse();
});

it('emits pnv: true when the user has at least one verified phone', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550100',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeIssuedClaims($minted['jwt']);

    expect($claims['pnv'])->toBeTrue();
});

it('omits dsf when the user has no enrolled second factor', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeIssuedClaims($minted['jwt']);

    expect($claims)->not->toHaveKey('dsf');
});

it('emits dsf: phone_code when the user has a verified phone with default_second_factor=true', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550100',
        'verified_at' => now(),
        'reserved_for_second_factor' => true,
        'default_second_factor' => true,
        'is_primary' => false,
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeIssuedClaims($minted['jwt']);

    expect($claims['pnv'])->toBeTrue();
    expect($claims['dsf'])->toBe('phone_code');
});

it('emits dsf: totp when the user has only a verified TOTP secret', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeIssuedClaims($minted['jwt']);

    expect($claims['dsf'])->toBe('totp');
});

it('prefers phone_code over totp when both are enrolled and phone is flagged default', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550100',
        'verified_at' => now(),
        'reserved_for_second_factor' => true,
        'default_second_factor' => true,
        'is_primary' => false,
    ]);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeIssuedClaims($minted['jwt']);

    expect($claims['dsf'])->toBe('phone_code');
});

it('falls through to totp when a verified phone is enrolled but not flagged default', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550100',
        'verified_at' => now(),
        'reserved_for_second_factor' => true,
        'default_second_factor' => false,
        'is_primary' => false,
    ]);
    TotpSecret::create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'verified_at' => now(),
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeIssuedClaims($minted['jwt']);

    expect($claims['dsf'])->toBe('totp');
});
