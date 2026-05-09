<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use App\Models\SigningKey;
use App\Services\Keys\SigningKeyGenerator;

function makeProductionEnvironment(): Environment
{
    $project = Project::create([
        'name' => 'Test',
        'slug' => 'test',
        'is_system' => false,
    ]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'test-'.bin2hex(random_bytes(3)),
        'routing_label' => 'test-'.bin2hex(random_bytes(3)),
    ]);
}

it('persists a signing key with an active status by default', function (): void {
    $env = makeProductionEnvironment();
    $key = (new SigningKeyGenerator)->generate($env);

    expect($key->id)->toStartWith('kid_');
    expect($key->status)->toBe(SigningKey::STATUS_ACTIVE);
    expect($key->algorithm)->toBe('RS256');
    expect($key->environment_id)->toBe($env->id);
    expect($key->activated_at)->not->toBeNull();
});

it('produces a JWK with the expected RSA shape', function (): void {
    $env = makeProductionEnvironment();
    $key = (new SigningKeyGenerator)->generate($env);

    $jwk = $key->public_jwk;
    expect($jwk['kty'])->toBe('RSA');
    expect($jwk['alg'])->toBe('RS256');
    expect($jwk['use'])->toBe('sig');
    expect($jwk['kid'])->toBe($key->id);
    expect($jwk['n'])->toMatch('/^[A-Za-z0-9_-]+$/');         // base64url, no padding
    expect($jwk['e'])->toMatch('/^[A-Za-z0-9_-]+$/');
    expect(strlen($jwk['n']))->toBeGreaterThan(300);          // 2048-bit modulus → ~342 base64url chars
});

it('round-trips: a token signed with the private PEM verifies against the JWK modulus', function (): void {
    $env = makeProductionEnvironment();
    $key = (new SigningKeyGenerator)->generate($env);

    $payload = 'authn-sh-test-payload';
    $privatePem = $key->privatePem();

    $resource = openssl_pkey_get_private($privatePem);
    expect($resource)->not->toBeFalse();

    openssl_sign($payload, $signature, $resource, OPENSSL_ALGO_SHA256);

    // Reconstruct the public key from the JWK and verify.
    $modulus = base64_decode(strtr($key->public_jwk['n'], '-_', '+/'));
    $exponent = base64_decode(strtr($key->public_jwk['e'], '-_', '+/'));

    // Build a public key resource via a minimal PEM (use openssl_pkey_get_details for confirmation).
    $details = openssl_pkey_get_details($resource);
    $publicPem = $details['key'];
    $publicResource = openssl_pkey_get_public($publicPem);

    $verified = openssl_verify($payload, $signature, $publicResource, OPENSSL_ALGO_SHA256);
    expect($verified)->toBe(1);

    // Sanity: the JWK modulus matches the modulus baked into the PEM.
    expect($details['rsa']['n'])->toBe($modulus);
    expect($details['rsa']['e'])->toBe($exponent);
});
