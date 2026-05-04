<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Jobs\Webhooks\DispatchWebhookDelivery;
use App\Models\Environment;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point every controller / observer / lifecycle service uses
 * to fire a webhook event:
 *
 *   $emitter->emit('user.created', UserResource::from($user, true), $env);
 *
 * The event is persisted FIRST so a crash mid-dispatch doesn't lose the
 * record. Endpoint matching honours both the explicit list and the `*`
 * wildcard. Each matching endpoint gets its own `webhook_deliveries` row
 * and a queued `DispatchWebhookDelivery` job.
 *
 * If no Environment is bound, the emitter no-ops so unit tests and CLI
 * callers don't have to fake one.
 */
final class Emitter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function emit(string $type, array $data, ?Environment $environment = null, bool $wasTest = false): ?WebhookEvent
    {
        $env = $environment ?? (app()->bound(Environment::class) ? app(Environment::class) : null);
        if (! $env instanceof Environment) {
            return null;
        }

        return DB::transaction(function () use ($env, $type, $data, $wasTest): WebhookEvent {
            $event = WebhookEvent::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'type' => $type,
                'data' => $data,
                'was_test' => $wasTest,
            ]);

            $endpoints = WebhookEndpoint::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->where('enabled', true)
                ->get()
                ->filter(fn (WebhookEndpoint $e) => $e->matches($type));

            foreach ($endpoints as $endpoint) {
                $delivery = WebhookDelivery::query()->create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'webhook_event_id' => $event->id,
                    'attempt' => 0,
                    'status' => WebhookDelivery::STATUS_PENDING,
                ]);
                DispatchWebhookDelivery::dispatch($delivery->id);
            }

            return $event;
        });
    }
}
