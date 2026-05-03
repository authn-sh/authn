<?php

declare(strict_types=1);

use App\Database\Scopes\EnvironmentScope;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function makeEnvFixture(string $slug = 'env-a'): Environment
{
    $project = Project::create([
        'name' => 'Project '.$slug,
        'slug' => 'project-'.$slug,
    ]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'frontend_api_host' => $slug.'.authn.local',
    ]);
}

it('hashes the password column on mass assignment with Argon2id', function (): void {
    $env = makeEnvFixture();

    $user = User::create([
        'environment_id' => $env->id,
        'password' => 'super-secret-password',
    ]);

    expect($user->password_hash)->not->toBe('super-secret-password');
    expect($user->password_hash)->toStartWith('$argon2id$');
    expect(Hash::check('super-secret-password', $user->password_hash))->toBeTrue();
    expect($user->password_changed_at)->not->toBeNull();
});

it('does not store a `password` column or attribute on the row', function (): void {
    $env = makeEnvFixture();

    $user = User::create([
        'environment_id' => $env->id,
        'password' => 'super-secret-password',
    ]);

    expect($user->getAttributes())->not->toHaveKey('password');
});

it('hides password_hash and private_metadata from JSON serialization', function (): void {
    $env = makeEnvFixture();

    $user = User::create([
        'environment_id' => $env->id,
        'password' => 'pw',
        'private_metadata' => ['internal' => 'value'],
        'public_metadata' => ['shown' => 'yes'],
    ]);

    $json = $user->toArray();
    expect($json)->not->toHaveKey('password_hash');
    expect($json)->not->toHaveKey('private_metadata');
    expect($json['public_metadata'])->toBe(['shown' => 'yes']);
});

it('enforces unique (environment_id, external_id) and (environment_id, username)', function (): void {
    $env = makeEnvFixture();

    User::create(['environment_id' => $env->id, 'external_id' => 'ext-1', 'username' => 'alice']);

    expect(fn () => User::create(['environment_id' => $env->id, 'external_id' => 'ext-1']))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(fn () => User::create(['environment_id' => $env->id, 'username' => 'alice']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('exposes lockout_expires_in_seconds derived from lockout_expires_at', function (): void {
    $env = makeEnvFixture();
    $user = User::create([
        'environment_id' => $env->id,
        'locked' => true,
        'lockout_expires_at' => now()->addMinutes(10),
    ]);

    expect($user->lockout_expires_in_seconds)->toBeGreaterThan(595);
    expect($user->lockout_expires_in_seconds)->toBeLessThanOrEqual(600);
});

it('returns null for lockout_expires_in_seconds when not locked', function (): void {
    $env = makeEnvFixture();
    $user = User::create(['environment_id' => $env->id]);

    expect($user->lockout_expires_in_seconds)->toBeNull();
});

it('respects the EnvironmentScope global scope when an env is bound', function (): void {
    $envA = makeEnvFixture('a');
    $envB = makeEnvFixture('b');

    User::create(['environment_id' => $envA->id, 'username' => 'alice']);
    User::create(['environment_id' => $envB->id, 'username' => 'bob']);

    app()->instance(Environment::class, $envA);
    expect(User::count())->toBe(1);
    expect(User::first()->username)->toBe('alice');

    app()->instance(Environment::class, $envB);
    expect(User::count())->toBe(1);
    expect(User::first()->username)->toBe('bob');
});

it('skips EnvironmentScope when no environment is bound', function (): void {
    $envA = makeEnvFixture('a');
    $envB = makeEnvFixture('b');

    User::create(['environment_id' => $envA->id, 'username' => 'alice']);
    User::create(['environment_id' => $envB->id, 'username' => 'bob']);

    app()->forgetInstance(Environment::class);
    expect(User::count())->toBe(2);
});

it('lets the caller bypass EnvironmentScope per query', function (): void {
    $envA = makeEnvFixture('a');
    $envB = makeEnvFixture('b');

    User::create(['environment_id' => $envA->id, 'username' => 'alice']);
    User::create(['environment_id' => $envB->id, 'username' => 'bob']);

    app()->instance(Environment::class, $envA);
    expect(User::withoutGlobalScope(EnvironmentScope::class)->count())->toBe(2);
});
