<?php

declare(strict_types=1);

use App\Models\BackupCode;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function makeEnvForBackupCode(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => $slug.'-p']);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints a bcc_-prefixed id', function (): void {
    $env = makeEnvForBackupCode();
    $user = User::create(['environment_id' => $env->id]);

    $row = BackupCode::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'code_hash' => Hash::make('plaintext-1'),
    ]);

    expect($row->id)->toStartWith('bcc_');
});

it('hides code_hash from array/JSON serialisation', function (): void {
    $env = makeEnvForBackupCode('b2');
    $user = User::create(['environment_id' => $env->id]);

    $row = BackupCode::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'code_hash' => Hash::make('plaintext-1'),
    ]);

    expect($row->toArray())->not->toHaveKey('code_hash');
});

it('has no updated_at column', function (): void {
    $env = makeEnvForBackupCode('b3');
    $user = User::create(['environment_id' => $env->id]);

    $row = BackupCode::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'code_hash' => Hash::make('plaintext-1'),
    ])->refresh();

    expect($row->getAttributes())->not->toHaveKey('updated_at');
});

it('isConsumed() + markConsumed() track consumption', function (): void {
    $env = makeEnvForBackupCode('b4');
    $user = User::create(['environment_id' => $env->id]);

    $row = BackupCode::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'code_hash' => Hash::make('plaintext-1'),
    ]);
    expect($row->isConsumed())->toBeFalse();

    $row->markConsumed();
    expect($row->refresh()->isConsumed())->toBeTrue();
    expect($row->consumed_at)->not->toBeNull();
});

it('scopes queries to the bound environment', function (): void {
    $envA = makeEnvForBackupCode('ba');
    $envB = makeEnvForBackupCode('bb');
    $userA = User::create(['environment_id' => $envA->id]);
    $userB = User::create(['environment_id' => $envB->id]);

    BackupCode::create([
        'environment_id' => $envA->id,
        'user_id' => $userA->id,
        'code_hash' => Hash::make('a'),
    ]);
    BackupCode::create([
        'environment_id' => $envB->id,
        'user_id' => $userB->id,
        'code_hash' => Hash::make('b'),
    ]);

    app()->instance(Environment::class, $envA);
    expect(BackupCode::query()->count())->toBe(1);

    app()->instance(Environment::class, $envB);
    expect(BackupCode::query()->count())->toBe(1);
});
