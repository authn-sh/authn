<?php

declare(strict_types=1);

use App\Jobs\Maintenance\ExpireSessions;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Webhooks\Emitter;

it('expires live sessions whose expire_at has passed and emits session.ended w/ reason=expired', function (): void {
    $project = Project::create(['name' => 'PSes', 'slug' => 'pses']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_DEVELOPMENT,
        'slug' => 'ses-exp',
        'routing_label' => 'ses-exp',
        'allowed_origins' => [],
    ]);
    $client = Client::create(['environment_id' => $env->id]);
    $user = User::create(['environment_id' => $env->id]);

    $expired = Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
        'expire_at' => now()->subMinute(),
    ]);
    $live = Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
        'expire_at' => now()->addHour(),
    ]);

    app(ExpireSessions::class)->handle(app(Emitter::class));

    expect($expired->fresh()->status)->toBe('expired');
    expect($live->fresh()->status)->toBe('active');

    $event = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('type', 'session.ended')
        ->latest('id')
        ->first();
    expect($event)->not->toBeNull();
    expect($event->data['reason'] ?? null)->toBe('expired');
    expect($event->data['id'] ?? null)->toBe($expired->id);
});
