<?php

declare(strict_types=1);

use App\Jobs\Maintenance\PruneMfaArtifacts;
use App\Models\BackupCode;
use App\Models\Environment;
use App\Models\Project;
use App\Models\TotpSecret;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function makeReaperEnv(string $slug, ?int $auditDays = null): Environment
{
    $project = Project::create(['name' => 'P-'.$slug, 'slug' => 'p-'.$slug]);
    $userSettings = $auditDays !== null ? ['audit_log_retention_days' => $auditDays] : [];

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
        'user_settings' => $userSettings,
    ]);
}

it('prunes unverified TotpSecrets older than 24h, keeps recent unverified, leaves verified untouched', function (): void {
    $env = makeReaperEnv('reaper-totp');
    $user = User::create(['environment_id' => $env->id]);

    $oldUnverified = TotpSecret::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'secret' => 'AAAA',
    ]);
    $oldUnverified->forceFill(['created_at' => now()->subHours(48)])->save();

    $recentUnverified = TotpSecret::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'secret' => 'BBBB',
    ]);

    $verified = TotpSecret::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'secret' => 'CCCC',
        'verified_at' => now(),
    ]);
    $verified->forceFill(['created_at' => now()->subHours(72)])->save();

    (new PruneMfaArtifacts)->handle();

    expect(TotpSecret::query()->withoutGlobalScopes()->where('id', $oldUnverified->id)->exists())->toBeFalse();
    expect(TotpSecret::query()->withoutGlobalScopes()->where('id', $recentUnverified->id)->exists())->toBeTrue();
    expect(TotpSecret::query()->withoutGlobalScopes()->where('id', $verified->id)->exists())->toBeTrue();
});

it('prunes consumed BackupCodes older than retention, keeps recent consumed and unspent rows', function (): void {
    $env = makeReaperEnv('reaper-bcc');
    $user = User::create(['environment_id' => $env->id]);

    $oldConsumed = BackupCode::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'code_hash' => Hash::make('a'),
        'consumed_at' => now()->subDays(120),
    ]);

    $recentConsumed = BackupCode::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'code_hash' => Hash::make('b'),
        'consumed_at' => now()->subDays(7),
    ]);

    $unspent = BackupCode::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'code_hash' => Hash::make('c'),
    ]);

    (new PruneMfaArtifacts)->handle();

    expect(BackupCode::query()->withoutGlobalScopes()->where('id', $oldConsumed->id)->exists())->toBeFalse();
    expect(BackupCode::query()->withoutGlobalScopes()->where('id', $recentConsumed->id)->exists())->toBeTrue();
    expect(BackupCode::query()->withoutGlobalScopes()->where('id', $unspent->id)->exists())->toBeTrue();
});

it('honours per-env audit_log_retention_days when pruning consumed codes', function (): void {
    $envA = makeReaperEnv('reaper-a', auditDays: 30);
    $envB = makeReaperEnv('reaper-b', auditDays: 90);
    $userA = User::create(['environment_id' => $envA->id]);
    $userB = User::create(['environment_id' => $envB->id]);

    $consumedA = BackupCode::create([
        'environment_id' => $envA->id,
        'user_id' => $userA->id,
        'code_hash' => Hash::make('a'),
        'consumed_at' => now()->subDays(45),
    ]);
    $consumedB = BackupCode::create([
        'environment_id' => $envB->id,
        'user_id' => $userB->id,
        'code_hash' => Hash::make('b'),
        'consumed_at' => now()->subDays(45),
    ]);

    (new PruneMfaArtifacts)->handle();

    expect(BackupCode::query()->withoutGlobalScopes()->where('id', $consumedA->id)->exists())->toBeFalse();
    expect(BackupCode::query()->withoutGlobalScopes()->where('id', $consumedB->id)->exists())->toBeTrue();
});

it('runs idempotently — second invocation deletes nothing more', function (): void {
    $env = makeReaperEnv('reaper-idem');
    $user = User::create(['environment_id' => $env->id]);
    BackupCode::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'code_hash' => Hash::make('a'),
        'consumed_at' => now()->subDays(120),
    ]);

    (new PruneMfaArtifacts)->handle();
    $afterFirst = BackupCode::query()->withoutGlobalScopes()->where('environment_id', $env->id)->count();
    (new PruneMfaArtifacts)->handle();
    $afterSecond = BackupCode::query()->withoutGlobalScopes()->where('environment_id', $env->id)->count();

    expect($afterFirst)->toBe(0);
    expect($afterSecond)->toBe(0);
});
