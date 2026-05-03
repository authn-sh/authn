<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Services\Keys\KeyGenerator;

function makeEnv(string $kind, string $host = 'acme.authn.sh'): Environment
{
    return new Environment([
        'project_id' => 'prj_01HKX9SY9V7H7TF8C8K7J9X4ZB',
        'kind' => $kind,
        'frontend_api_host' => $host,
    ]);
}

it('produces sk_live_ keys for production environments', function (): void {
    $key = (new KeyGenerator)->secretKey(makeEnv('production'));

    expect($key)->toStartWith('sk_live_');
    expect(strlen($key))->toBeGreaterThanOrEqual(40);
});

it('produces sk_test_ keys for non-production environments', function (): void {
    foreach (['development', 'staging'] as $kind) {
        $key = (new KeyGenerator)->secretKey(makeEnv($kind));
        expect($key)->toStartWith('sk_test_');
    }
});

it('produces unique secret keys across calls', function (): void {
    $generator = new KeyGenerator;
    $env = makeEnv('production');

    $keys = collect(range(1, 50))->map(fn () => $generator->secretKey($env));

    expect($keys->unique())->toHaveCount(50);
});

it('produces a publishable key whose body decodes back to the FAPI host', function (): void {
    $key = (new KeyGenerator)->publishableKey(makeEnv('production', 'acme.authn.sh'));

    expect($key)->toStartWith('pk_live_');
    $payload = base64_decode(substr($key, strlen('pk_live_')));
    expect($payload)->toBe('acme.authn.sh$');
});

it('produces pk_test_ keys for non-production environments', function (): void {
    $key = (new KeyGenerator)->publishableKey(makeEnv('development', 'dev.authn.sh'));

    expect($key)->toStartWith('pk_test_');
    $payload = base64_decode(substr($key, strlen('pk_test_')));
    expect($payload)->toBe('dev.authn.sh$');
});

it('hashes a secret to a 64-character lowercase hex string', function (): void {
    $hash = (new KeyGenerator)->hash('sk_test_anything');

    expect($hash)->toMatch('/^[0-9a-f]{64}$/');
});
