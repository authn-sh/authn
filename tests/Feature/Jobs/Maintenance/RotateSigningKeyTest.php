<?php

declare(strict_types=1);

use App\Jobs\Maintenance\RotateSigningKey;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SigningKey;
use App\Services\Keys\SigningKeyGenerator;
use App\Webhooks\Emitter;
use Illuminate\Support\Carbon;


it('walks the active → pending → promote → retiring → expired state machine', function (): void {
    $project = Project::create(['name' => 'PRot', 'slug' => 'prot']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_DEVELOPMENT,
        'slug' => 'rot-1',
        'routing_label' => 'rot-1',
        'allowed_origins' => [],
        // Tighten the cadence so the test doesn't have to step through 90 days.
        'user_settings' => [
            'signing_keys' => [
                'rotation_cadence_days' => 1,         // 86400s
                'pre_publish_window_seconds' => 600,  // 10 min before rotation
                'retire_window_seconds' => 300,       // 5 min retirement window
            ],
        ],
    ]);

    $start = Carbon::create(2026, 1, 1, 12);
    Carbon::setTestNow($start);
    $active = app(SigningKeyGenerator::class)->generate($env, SigningKey::STATUS_ACTIVE);

    $job = app(RotateSigningKey::class);
    $emitter = app(Emitter::class);

    // T+1h: nothing should change yet (still well before pre_publish window).
    Carbon::setTestNow($start->copy()->addHour());
    $job->handle(app(SigningKeyGenerator::class), $emitter);
    expect(SigningKey::query()->withoutGlobalScopes()->where('environment_id', $env->id)->where('status', SigningKey::STATUS_PENDING)->count())->toBe(0);

    // T+(rotation - pre_publish + 1s): pre-publish a pending key.
    Carbon::setTestNow($start->copy()->addSeconds(86400 - 600 + 1));
    $job->handle(app(SigningKeyGenerator::class), $emitter);
    $pending = SigningKey::query()->withoutGlobalScopes()->where('environment_id', $env->id)->where('status', SigningKey::STATUS_PENDING)->first();
    expect($pending)->not->toBeNull();

    // T+(rotation + 1s): promote pending → active; demote old active → retiring.
    Carbon::setTestNow($start->copy()->addSeconds(86400 + 1));
    $job->handle(app(SigningKeyGenerator::class), $emitter);
    expect($pending->fresh()->status)->toBe(SigningKey::STATUS_ACTIVE);
    expect($active->fresh()->status)->toBe(SigningKey::STATUS_RETIRING);
    expect($active->fresh()->retire_at?->getTimestamp())->toBe($start->copy()->addSeconds(86400 + 1 + 300)->getTimestamp());

    // T+(rotation + retire_window + 1s): the retiring key flips to expired.
    Carbon::setTestNow($start->copy()->addSeconds(86400 + 300 + 2));
    $job->handle(app(SigningKeyGenerator::class), $emitter);
    expect($active->fresh()->status)->toBe(SigningKey::STATUS_EXPIRED);

    Carbon::setTestNow();
});
