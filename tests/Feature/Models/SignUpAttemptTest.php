<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SignUpAttempt;


function suiFixture(): array
{
    $project = Project::create(['name' => 'P', 'slug' => 'p']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'env',
        'routing_label' => 'env',
    ]);
    $client = Client::create(['environment_id' => $env->id]);

    return ['env' => $env, 'client' => $client];
}

it('defaults status to missing_requirements on create', function (): void {
    $f = suiFixture();
    $a = SignUpAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
    ]);

    expect($a->id)->toStartWith('sui_');
    expect($a->status)->toBe('missing_requirements');
    expect($a->missing_fields)->toBe([]);
    expect($a->unverified_fields)->toBe([]);
    expect($a->verifications)->toBe([]);
});

it('allows missing_requirements → complete and missing_requirements → transferable', function (): void {
    $f = suiFixture();
    $a = SignUpAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
    ]);

    $a->status = SignUpAttempt::STATUS_COMPLETE;
    $a->save();
    expect($a->fresh()->status)->toBe('complete');

    $b = SignUpAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
    ]);
    $b->status = SignUpAttempt::STATUS_TRANSFERABLE;
    $b->save();
    expect($b->fresh()->status)->toBe('transferable');
});

it('rejects illegal status transitions', function (): void {
    $f = suiFixture();
    $a = SignUpAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
    ]);
    $a->status = SignUpAttempt::STATUS_COMPLETE;
    $a->save();

    $a->status = SignUpAttempt::STATUS_MISSING_REQUIREMENTS;
    expect(fn () => $a->save())->toThrow(InvalidArgumentException::class);
});

it('hides password_hash and transfer_token from JSON serialization', function (): void {
    $f = suiFixture();
    $a = SignUpAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'password_hash' => 'leaky',
        'transfer_token' => 'leaky',
    ]);

    expect($a->toArray())->not->toHaveKey('password_hash');
    expect($a->toArray())->not->toHaveKey('transfer_token');
});

it('persists jsonb columns as arrays', function (): void {
    $f = suiFixture();
    $a = SignUpAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'unsafe_metadata' => ['plan' => 'pro'],
        'verifications' => ['email_address' => 'ver_xyz'],
        'missing_fields' => ['username'],
        'unverified_fields' => ['email_address'],
    ]);

    $a = $a->fresh();
    expect($a->unsafe_metadata)->toBe(['plan' => 'pro']);
    expect($a->verifications)->toBe(['email_address' => 'ver_xyz']);
    expect($a->missing_fields)->toBe(['username']);
    expect($a->unverified_fields)->toBe(['email_address']);
});
