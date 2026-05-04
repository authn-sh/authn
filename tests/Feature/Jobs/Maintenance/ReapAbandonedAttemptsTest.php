<?php

declare(strict_types=1);

use App\Jobs\Maintenance\ReapAbandonedAttempts;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SignInAttempt;
use App\Models\SignUpAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function maintenanceEnv(): Environment
{
    $project = Project::create(['name' => 'P-'.bin2hex(random_bytes(3)), 'slug' => 'p-'.bin2hex(random_bytes(3))]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_DEVELOPMENT,
        'slug' => 'maint-'.bin2hex(random_bytes(3)),
        'frontend_api_host' => 'maint.authn.local',
        'allowed_origins' => [],
    ]);
}

it('flips expired attempts to abandoned and detaches them from their Client', function (): void {
    $env = maintenanceEnv();
    $client = Client::create(['environment_id' => $env->id]);

    $expired = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'identifier' => 'old@example.com',
        'abandon_at' => now()->subHour(),
    ]);
    $client->forceFill(['current_sign_in_attempt_id' => $expired->id])->saveQuietly();

    $live = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'identifier' => 'new@example.com',
        'abandon_at' => now()->addHours(2),
    ]);

    (new ReapAbandonedAttempts)->handle();

    expect($expired->fresh()->status)->toBe('abandoned');
    expect($live->fresh()->status)->not->toBe('abandoned');
    expect($client->fresh()->current_sign_in_attempt_id)->toBeNull();
});

it('also reaps SignUpAttempt rows', function (): void {
    $env = maintenanceEnv();
    $client = Client::create(['environment_id' => $env->id]);

    $expired = SignUpAttempt::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'abandon_at' => now()->subHour(),
    ]);
    $client->forceFill(['current_sign_up_attempt_id' => $expired->id])->saveQuietly();

    (new ReapAbandonedAttempts)->handle();

    expect($expired->fresh()->status)->toBe('abandoned');
    expect($client->fresh()->current_sign_up_attempt_id)->toBeNull();
});
