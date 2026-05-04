<?php

declare(strict_types=1);

use App\Jobs\Webhooks\DispatchWebhookDelivery;
use App\Models\Environment;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Webhooks\Signer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function bootWebhookFixture(): array
{
    $project = Project::create(['name' => 'P-'.bin2hex(random_bytes(3)), 'slug' => 'p-'.bin2hex(random_bytes(3))]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'wh-'.bin2hex(random_bytes(3)),
        'frontend_api_host' => 'wh.authn.local',
        'allowed_origins' => [],
    ]);
    $endpoint = WebhookEndpoint::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'url' => 'https://customer.example.com/hook',
        'signing_secret' => WebhookEndpoint::mintSecret(),
        'enabled_event_types' => ['*'],
        'enabled' => true,
    ]);
    $event = WebhookEvent::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'type' => 'user.created',
        'data' => ['id' => 'user_a'],
        'was_test' => false,
    ]);
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'webhook_event_id' => $event->id,
        'attempt' => 0,
        'status' => WebhookDelivery::STATUS_PENDING,
    ]);

    return ['env' => $env, 'endpoint' => $endpoint, 'event' => $event, 'delivery' => $delivery];
}

it('marks succeeded on a 2xx response and signs the payload', function (): void {
    Http::fake([
        'customer.example.com/*' => Http::response('ok', 200),
    ]);
    $f = bootWebhookFixture();

    (new DispatchWebhookDelivery($f['delivery']->id))->handle(app(Signer::class));

    $delivery = $f['delivery']->fresh();
    expect($delivery->status)->toBe('succeeded');
    expect($delivery->response_status)->toBe(200);

    Http::assertSent(function ($req) use ($f) {
        return str_contains($req->url(), 'customer.example.com/hook')
            && $req->hasHeader('svix-id', $f['event']->id)
            && $req->hasHeader('svix-timestamp')
            && str_starts_with((string) $req->header('svix-signature')[0], 'v1,')
            && $req->hasHeader('User-Agent', 'authn.sh-webhooks/0.1');
    });
});

it('terminal-fails on a 4xx response without retrying', function (): void {
    Bus::fake();
    Http::fake([
        'customer.example.com/*' => Http::response('bad', 422),
    ]);
    $f = bootWebhookFixture();

    (new DispatchWebhookDelivery($f['delivery']->id))->handle(app(Signer::class));

    expect($f['delivery']->fresh()->status)->toBe('failed');
    Bus::assertNotDispatched(DispatchWebhookDelivery::class);
});

it('re-enqueues itself with backoff on a 5xx', function (): void {
    Bus::fake();
    Http::fake([
        'customer.example.com/*' => Http::response('boom', 503),
    ]);
    $f = bootWebhookFixture();

    (new DispatchWebhookDelivery($f['delivery']->id))->handle(app(Signer::class));

    $delivery = $f['delivery']->fresh();
    expect($delivery->status)->toBe('pending');
    expect($delivery->next_retry_at)->not->toBeNull();
    Bus::assertDispatchedTimes(DispatchWebhookDelivery::class, 1);
});

it('abandons after the schedule is exhausted', function (): void {
    Bus::fake();
    Http::fake([
        'customer.example.com/*' => Http::response('boom', 503),
    ]);
    $f = bootWebhookFixture();
    $f['delivery']->forceFill(['attempt' => count(WebhookDelivery::RETRY_DELAYS_SECONDS)])->save();

    (new DispatchWebhookDelivery($f['delivery']->id))->handle(app(Signer::class));

    expect($f['delivery']->fresh()->status)->toBe('abandoned');
    Bus::assertNotDispatched(DispatchWebhookDelivery::class);
});

it('auto-disables the endpoint after only-failure deliveries in the window', function (): void {
    Http::fake([
        'customer.example.com/*' => Http::response('bad', 422),
    ]);
    $f = bootWebhookFixture();
    // Plant prior failure deliveries inside the 7-day window.
    for ($i = 0; $i < 3; $i++) {
        $event = WebhookEvent::query()->withoutGlobalScopes()->create([
            'environment_id' => $f['env']->id,
            'type' => 'user.created',
            'data' => ['id' => "user_{$i}"],
        ]);
        WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $f['endpoint']->id,
            'webhook_event_id' => $event->id,
            'status' => WebhookDelivery::STATUS_FAILED,
            'completed_at' => now()->subDays(2),
        ]);
    }

    (new DispatchWebhookDelivery($f['delivery']->id))->handle(app(Signer::class));

    $endpoint = $f['endpoint']->fresh();
    expect($endpoint->enabled)->toBeFalse();
    expect($endpoint->disabled_at)->not->toBeNull();
});
