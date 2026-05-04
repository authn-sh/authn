<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Support\AllowedOrigins;

function envWith(array $allowed, string $kind = Environment::KIND_PRODUCTION): Environment
{
    return new Environment([
        'project_id' => 'prj_01HKX9SY9V7H7TF8C8K7J9X4ZB',
        'kind' => $kind,
        'slug' => 'env',
        'routing_label' => 'env',
        'allowed_origins' => $allowed,
    ]);
}

it('matches an exact entry', function (): void {
    $env = envWith(['https://app.example.com']);
    expect(AllowedOrigins::matches($env, 'https://app.example.com'))->toBeTrue();
});

it('strips default ports on both sides', function (): void {
    $env = envWith(['https://app.example.com']);
    expect(AllowedOrigins::matches($env, 'https://app.example.com:443'))->toBeTrue();

    $env2 = envWith(['https://app.example.com:443']);
    expect(AllowedOrigins::matches($env2, 'https://app.example.com'))->toBeTrue();

    $env3 = envWith(['http://localhost:80']);
    expect(AllowedOrigins::matches($env3, 'http://localhost'))->toBeTrue();
});

it('refuses non-default ports that do not match', function (): void {
    $env = envWith(['https://app.example.com']);
    expect(AllowedOrigins::matches($env, 'https://app.example.com:8443'))->toBeFalse();
});

it('lowercases the host when comparing', function (): void {
    $env = envWith(['https://APP.example.com']);
    expect(AllowedOrigins::matches($env, 'https://app.example.com'))->toBeTrue();
});

it('refuses an empty / malformed origin', function (): void {
    $env = envWith(['https://app.example.com']);
    expect(AllowedOrigins::matches($env, ''))->toBeFalse();
    expect(AllowedOrigins::matches($env, 'not-a-url'))->toBeFalse();
});

it('refuses an origin not in the list', function (): void {
    $env = envWith(['https://app.example.com']);
    expect(AllowedOrigins::matches($env, 'https://attacker.example'))->toBeFalse();
});

it('honours port wildcard patterns only in development envs', function (): void {
    $devEnv = envWith(['http://localhost:*'], Environment::KIND_DEVELOPMENT);
    expect(AllowedOrigins::matches($devEnv, 'http://localhost:5173'))->toBeTrue();
    expect(AllowedOrigins::matches($devEnv, 'http://localhost:3000'))->toBeTrue();

    $prodEnv = envWith(['http://localhost:*'], Environment::KIND_PRODUCTION);
    expect(AllowedOrigins::matches($prodEnv, 'http://localhost:5173'))->toBeFalse();
});

it('matches 127.0.0.1 wildcard pattern in development envs', function (): void {
    $env = envWith(['http://127.0.0.1:*'], Environment::KIND_DEVELOPMENT);
    expect(AllowedOrigins::matches($env, 'http://127.0.0.1:8000'))->toBeTrue();
    expect(AllowedOrigins::matches($env, 'http://127.0.0.1'))->toBeTrue(); // no port
});

it('handles an empty allow list', function (): void {
    $env = envWith([]);
    expect(AllowedOrigins::matches($env, 'https://app.example.com'))->toBeFalse();
});
