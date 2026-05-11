<?php

declare(strict_types=1);

use App\Auth\Passkey\RpConfigResolver;
use App\Models\Environment;
use App\Models\Project;

function makeEnvForRpResolver(string $slug = 'env', array $allowed = []): Environment
{
    config([
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.routing_mode' => 'subdomain',
        'authn.app_port_suffix' => '',
    ]);
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
        'allowed_origins' => $allowed,
    ]);
}

it('rp.id is the env FAPI host', function (): void {
    $env = makeEnvForRpResolver('acme');

    expect((new RpConfigResolver)->rpId($env))->toBe('acme.authn.local');
});

it('rpEntity.name falls back to the configured application name', function (): void {
    $env = makeEnvForRpResolver('acme');
    $env->forceFill(['appearance' => array_merge(
        (array) $env->appearance,
        ['application_name' => 'Acme Inc.'],
    )])->save();

    $entity = (new RpConfigResolver)->rpEntity($env->refresh());
    expect($entity->name)->toBe('Acme Inc.');
    expect($entity->id)->toBe('acme.authn.local');
});

it('allowedOrigins always includes the primary FAPI URL and merges Environment.allowed_origins', function (): void {
    $env = makeEnvForRpResolver('acme', ['https://acme.example.com', 'https://app.acme.example.com']);

    $origins = (new RpConfigResolver)->allowedOrigins($env);

    expect($origins)->toContain('https://acme.authn.local');
    expect($origins)->toContain('https://acme.example.com');
    expect($origins)->toContain('https://app.acme.example.com');
    expect(count($origins))->toBe(3);
});

it('allowedOrigins respects the scheme + port-suffix config', function (): void {
    $env = makeEnvForRpResolver('dev');
    config(['authn.app_scheme' => 'http', 'authn.app_port_suffix' => ':8080']);

    $origins = (new RpConfigResolver)->allowedOrigins($env);

    expect($origins[0])->toBe('http://dev.authn.local:8080');
});
