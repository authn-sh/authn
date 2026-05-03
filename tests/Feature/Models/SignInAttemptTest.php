<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SignInAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siaFixture(): array
{
    $project = Project::create(['name' => 'P', 'slug' => 'p']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'env',
        'frontend_api_host' => 'env.authn.local',
    ]);
    $client = Client::create(['environment_id' => $env->id]);

    return ['env' => $env, 'client' => $client];
}

it('defaults status to needs_identifier and abandon_at to ~24h on create', function (): void {
    $f = siaFixture();
    $attempt = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
    ]);

    expect($attempt->id)->toStartWith('sia_');
    expect($attempt->status)->toBe('needs_identifier');
    expect($attempt->abandon_at->getTimestamp())->toBeGreaterThan(now()->addHours(23)->getTimestamp());
    expect($attempt->abandon_at->getTimestamp())->toBeLessThanOrEqual(now()->addHours(24)->getTimestamp() + 1);
});

it('allows the documented status transitions', function (): void {
    $f = siaFixture();
    $a = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
    ]);

    $a->status = SignInAttempt::STATUS_NEEDS_FIRST_FACTOR;
    $a->save();
    expect($a->fresh()->status)->toBe('needs_first_factor');

    $a->status = SignInAttempt::STATUS_NEEDS_SECOND_FACTOR;
    $a->save();
    expect($a->fresh()->status)->toBe('needs_second_factor');

    $a->status = SignInAttempt::STATUS_COMPLETE;
    $a->save();
    expect($a->fresh()->status)->toBe('complete');
});

it('rejects illegal status transitions at the model layer', function (): void {
    $f = siaFixture();
    $a = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
    ]);

    // needs_identifier → needs_second_factor is not allowed; must go through
    // needs_first_factor first.
    $a->status = SignInAttempt::STATUS_NEEDS_SECOND_FACTOR;
    expect(fn () => $a->save())
        ->toThrow(InvalidArgumentException::class, 'Illegal SignInAttempt transition');
});

it('forbids transitions out of complete', function (): void {
    $f = siaFixture();
    $a = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
    ]);
    $a->status = SignInAttempt::STATUS_NEEDS_FIRST_FACTOR;
    $a->save();
    $a->status = SignInAttempt::STATUS_COMPLETE;
    $a->save();

    $a->status = SignInAttempt::STATUS_NEEDS_FIRST_FACTOR;
    expect(fn () => $a->save())->toThrow(InvalidArgumentException::class);
});

it('hides transfer_token from JSON serialization', function (): void {
    $f = siaFixture();
    $a = SignInAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'transfer_token' => 'should-not-leak',
    ]);

    expect($a->toArray())->not->toHaveKey('transfer_token');
});
