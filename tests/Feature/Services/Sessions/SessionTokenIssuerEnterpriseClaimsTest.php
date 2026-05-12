<?php

declare(strict_types=1);

use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\SignInAttempt;
use App\Models\Verification;
use App\Services\Sessions\SessionTokenIssuer;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Tests\Feature\Http\Me\MeTestSupport;

function decodeEnterpriseClaims(string $jwt): array
{
    $config = Configuration::forSymmetricSigner(
        new Sha256,
        InMemory::plainText(str_repeat('x', 64)),
    );

    return $config->parser()->parse($jwt)->claims()->all();
}

it('omits entcon + entacc when the session was not produced by an enterprise SSO sign-in', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeEnterpriseClaims($minted['jwt']);

    expect($claims)->not->toHaveKey('entcon');
    expect($claims)->not->toHaveKey('entacc');
});

it('emits entcon + entacc when the parent SignIn was verified via enterprise_sso', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $account = EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'enterprise_connection_id' => $conn->id,
    ]);

    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $auth['session']->client_id,
        'status' => SignInAttempt::STATUS_COMPLETE,
        'created_session_id' => $auth['session']->id,
    ]);
    Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => Verification::STRATEGY_ENTERPRISE_SSO,
        'status' => Verification::STATUS_VERIFIED,
        'expire_at' => now()->addHour(),
        'verified_at' => now(),
        'attempts' => 1,
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeEnterpriseClaims($minted['jwt']);

    expect($claims)->toHaveKey('entcon');
    expect($claims)->toHaveKey('entacc');
    expect($claims['entcon'])->toBe($conn->id);
    expect($claims['entacc'])->toBe($account->id);
});

it('emits entcon + entacc for the saml strategy too (parity with enterprise_sso)', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $account = EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'enterprise_connection_id' => $conn->id,
    ]);

    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $auth['session']->client_id,
        'status' => SignInAttempt::STATUS_COMPLETE,
        'created_session_id' => $auth['session']->id,
    ]);
    Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => Verification::STRATEGY_SAML,
        'status' => Verification::STATUS_VERIFIED,
        'expire_at' => now()->addHour(),
        'verified_at' => now(),
        'attempts' => 1,
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeEnterpriseClaims($minted['jwt']);

    expect($claims['entcon'])->toBe($conn->id);
    expect($claims['entacc'])->toBe($account->id);
});

it('omits the claims when the Verification is still unverified', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'enterprise_connection_id' => $conn->id,
    ]);
    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $auth['session']->client_id,
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
        'created_session_id' => $auth['session']->id,
    ]);
    Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => Verification::STRATEGY_ENTERPRISE_SSO,
        'status' => Verification::STATUS_UNVERIFIED,
        'expire_at' => now()->addHour(),
        'attempts' => 0,
    ]);

    $minted = app(SessionTokenIssuer::class)->mint($auth['session']->fresh());
    $claims = decodeEnterpriseClaims($minted['jwt']);

    expect($claims)->not->toHaveKey('entcon');
});
