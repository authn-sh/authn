<?php

declare(strict_types=1);

use App\Jobs\Maintenance\PruneVerificationCodes;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes consumed and stale-past-24h verification codes; keeps recent unconsumed', function (): void {
    $project = Project::create(['name' => 'PVC', 'slug' => 'pvc']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_DEVELOPMENT,
        'slug' => 'vc-prune',
        'routing_label' => 'vc-prune',
        'allowed_origins' => [],
    ]);
    $user = User::create(['environment_id' => $env->id]);
    $email = EmailAddress::create([
        'environment_id' => $env->id, 'user_id' => $user->id,
        'email_address' => 'vc@example.com', 'verified_at' => now(), 'is_primary' => true,
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $email->getMorphClass(),
        'verifiable_id' => $email->id,
        'strategy' => 'email_code',
        'status' => 'unverified',
        'attempts' => 0,
        'expire_at' => now()->addMinutes(10),
    ]);

    $consumed = VerificationCode::create([
        'verification_id' => $verification->id,
        'code_hash' => str_repeat('a', 64),
        'purpose' => 'email_code',
        'expires_at' => now()->addMinutes(10),
        'consumed_at' => now()->subMinute(),
        'created_at' => now(),
    ]);
    $stale = VerificationCode::create([
        'verification_id' => $verification->id,
        'code_hash' => str_repeat('b', 64),
        'purpose' => 'email_code',
        'expires_at' => now()->subDays(2),
        'created_at' => now()->subDays(2),
    ]);
    $fresh = VerificationCode::create([
        'verification_id' => $verification->id,
        'code_hash' => str_repeat('c', 64),
        'purpose' => 'email_code',
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
    ]);

    (new PruneVerificationCodes)->handle();

    expect(VerificationCode::query()->where('id', $consumed->id)->exists())->toBeFalse();
    expect(VerificationCode::query()->where('id', $stale->id)->exists())->toBeFalse();
    expect(VerificationCode::query()->where('id', $fresh->id)->exists())->toBeTrue();
    // Verification itself must remain (audit value).
    expect(Verification::query()->withoutGlobalScopes()->where('id', $verification->id)->exists())->toBeTrue();
});
