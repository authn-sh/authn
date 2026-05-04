<?php

declare(strict_types=1);

use App\Jobs\Webhooks\DispatchWebhookDelivery;
use App\Models\Environment;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Webhooks\Emitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function makeEnvForWebhooks(): Environment
{
    $project = Project::create(['name' => 'P-'.bin2hex(random_bytes(3)), 'slug' => 'p-'.bin2hex(random_bytes(3))]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'wh-'.bin2hex(random_bytes(3)),
        'routing_label' => 'wh',
        'allowed_origins' => [],
    ]);
}

function makeEndpoint(Environment $env, array $types = ['*']): WebhookEndpoint
{
    return WebhookEndpoint::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'url' => 'https://customer.example.com/hook',
        'signing_secret' => WebhookEndpoint::mintSecret(),
        'enabled_event_types' => $types,
        'enabled' => true,
    ]);
}

it('persists an event row and dispatches one job per matching endpoint', function (): void {
    Bus::fake();
    $env = makeEnvForWebhooks();
    makeEndpoint($env);
    makeEndpoint($env);

    $event = app(Emitter::class)->emit('user.created', ['id' => 'user_x'], $env);

    expect($event)->not->toBeNull();
    expect(WebhookEvent::query()->withoutGlobalScopes()->where('id', $event->id)->exists())->toBeTrue();
    expect(WebhookDelivery::query()->where('webhook_event_id', $event->id)->count())->toBe(2);
    Bus::assertDispatchedTimes(DispatchWebhookDelivery::class, 2);
});

it('filters endpoints by enabled_event_types', function (): void {
    Bus::fake();
    $env = makeEnvForWebhooks();
    makeEndpoint($env, ['user.created']);
    makeEndpoint($env, ['session.ended']);

    app(Emitter::class)->emit('user.created', ['id' => 'user_y'], $env);
    Bus::assertDispatchedTimes(DispatchWebhookDelivery::class, 1);
});

it('skips disabled endpoints', function (): void {
    Bus::fake();
    $env = makeEnvForWebhooks();
    $disabled = makeEndpoint($env);
    $disabled->forceFill(['enabled' => false])->save();

    app(Emitter::class)->emit('user.created', ['id' => 'user_z'], $env);
    Bus::assertDispatchedTimes(DispatchWebhookDelivery::class, 0);
});
