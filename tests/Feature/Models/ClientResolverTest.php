<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Client\ClientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function clientEnv(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'frontend_api_host' => $slug.'.authn.local',
    ]);
}

it('round-trips a valid cookie back to the Client row', function (): void {
    $env = clientEnv();
    $client = Client::create(['environment_id' => $env->id]);

    $resolver = new ClientResolver;
    $cookie = $resolver->mintCookieValue($client);

    $resolved = $resolver->fromCookie($cookie, $env);
    expect($resolved?->id)->toBe($client->id);
});

it('rejects null / empty cookie values', function (): void {
    $env = clientEnv();
    $resolver = new ClientResolver;

    expect($resolver->fromCookie(null, $env))->toBeNull();
    expect($resolver->fromCookie('', $env))->toBeNull();
});

it('rejects a cookie without the . separator', function (): void {
    $env = clientEnv();
    $resolver = new ClientResolver;

    expect($resolver->fromCookie('client_01HKX9SY9V7H7TF8C8K7J9X4ZB', $env))->toBeNull();
});

it('rejects a cookie whose id portion is malformed', function (): void {
    $env = clientEnv();
    $resolver = new ClientResolver;

    expect($resolver->fromCookie('not-an-id.signature', $env))->toBeNull();
});

it('rejects a cookie whose signature does not match the row secret', function (): void {
    $env = clientEnv();
    $client = Client::create(['environment_id' => $env->id]);

    $resolver = new ClientResolver;
    $valid = $resolver->mintCookieValue($client);

    // Flip the last character to corrupt the signature.
    $tampered = substr($valid, 0, -1).(substr($valid, -1) === 'a' ? 'b' : 'a');

    expect($resolver->fromCookie($tampered, $env))->toBeNull();
});

it('rejects a cookie issued for a different environment', function (): void {
    $envA = clientEnv('a');
    $envB = clientEnv('b');
    $client = Client::create(['environment_id' => $envA->id]);

    $resolver = new ClientResolver;
    $cookie = $resolver->mintCookieValue($client);

    expect($resolver->fromCookie($cookie, $envA)->id)->toBe($client->id);
    expect($resolver->fromCookie($cookie, $envB))->toBeNull();
});

it('mints a fresh cookie_secret on Client::create', function (): void {
    $env = clientEnv();
    $a = Client::create(['environment_id' => $env->id]);
    $b = Client::create(['environment_id' => $env->id]);

    expect($a->cookie_secret)->not->toBeEmpty();
    expect($b->cookie_secret)->not->toBeEmpty();
    expect($a->cookie_secret)->not->toBe($b->cookie_secret);
});

it('hides cookie_secret from JSON serialization', function (): void {
    $env = clientEnv();
    $client = Client::create(['environment_id' => $env->id]);

    expect($client->toArray())->not->toHaveKey('cookie_secret');
});
