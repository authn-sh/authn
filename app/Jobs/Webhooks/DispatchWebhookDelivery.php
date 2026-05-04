<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Webhooks\Signer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single-shot dispatcher for one WebhookDelivery row. Re-enqueues itself
 * (with a delay drawn from WebhookDelivery::RETRY_DELAYS_SECONDS) on
 * 5xx / network errors; treats 4xx as terminal failure (consumer error).
 *
 * Self-disable rule: if the endpoint has been all-failure for
 * `WebhookEndpoint::AUTO_DISABLE_AFTER_DAYS` consecutive days, flip its
 * `enabled` to false and stamp `disabled_at`.
 */
final class DispatchWebhookDelivery implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // We manage retries explicitly via re-enqueue.

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(Signer $signer): void
    {
        $delivery = WebhookDelivery::query()->where('id', $this->deliveryId)->first();
        if ($delivery === null) {
            return;
        }
        if (in_array($delivery->status, [WebhookDelivery::STATUS_SUCCEEDED, WebhookDelivery::STATUS_ABANDONED, WebhookDelivery::STATUS_FAILED], true)) {
            return;
        }

        $endpoint = WebhookEndpoint::query()->withoutGlobalScopes()->where('id', $delivery->webhook_endpoint_id)->first();
        $event = WebhookEvent::query()->withoutGlobalScopes()->where('id', $delivery->webhook_event_id)->first();
        if ($endpoint === null || $event === null) {
            $delivery->forceFill(['status' => WebhookDelivery::STATUS_ABANDONED, 'completed_at' => now()])->save();

            return;
        }

        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_IN_PROGRESS,
            'attempt' => $delivery->attempt + 1,
            'requested_at' => now(),
        ])->save();

        $body = json_encode([
            'type' => $event->type,
            'object' => 'event',
            'data' => $event->data,
            'timestamp' => $event->created_at->getTimestamp(),
            'instance_id' => $event->environment_id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $timestamp = now()->getTimestamp();
        $signature = $signer->headerFor($endpoint, $body, $event->id, $timestamp);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'authn.sh-webhooks/0.1',
                'svix-id' => $event->id,
                'svix-timestamp' => (string) $timestamp,
                'svix-signature' => $signature,
            ])->withBody($body, 'application/json')->post($endpoint->url);

            $status = $response->status();
            $delivery->forceFill([
                'response_status' => $status,
                'response_body' => substr((string) $response->body(), 0, WebhookDelivery::RESPONSE_BODY_LIMIT),
                'response_headers' => $response->headers(),
            ])->save();

            if ($status >= 200 && $status < 300) {
                $delivery->forceFill(['status' => WebhookDelivery::STATUS_SUCCEEDED, 'completed_at' => now()])->save();
                $this->maybeAutoDisable($endpoint);

                return;
            }

            // 4xx: consumer error — terminal, no retries.
            if ($status >= 400 && $status < 500) {
                $delivery->forceFill(['status' => WebhookDelivery::STATUS_FAILED, 'completed_at' => now()])->save();
                $this->maybeAutoDisable($endpoint);

                return;
            }

            // 5xx + 3xx (we don't follow redirects): retry per schedule.
            $this->retryOrAbandon($delivery);
            $this->maybeAutoDisable($endpoint);
        } catch (Throwable $e) {
            Log::warning('webhook_dispatch_exception', ['delivery_id' => $delivery->id, 'message' => $e->getMessage()]);
            $delivery->forceFill(['response_body' => substr($e->getMessage(), 0, WebhookDelivery::RESPONSE_BODY_LIMIT)])->save();
            $this->retryOrAbandon($delivery);
            $this->maybeAutoDisable($endpoint);
        }
    }

    private function retryOrAbandon(WebhookDelivery $delivery): void
    {
        $cursor = $delivery->attempt;
        if ($cursor >= count(WebhookDelivery::RETRY_DELAYS_SECONDS)) {
            $delivery->forceFill(['status' => WebhookDelivery::STATUS_ABANDONED, 'completed_at' => now()])->save();

            return;
        }
        $delaySeconds = WebhookDelivery::RETRY_DELAYS_SECONDS[$cursor - 1] ?? 5;
        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_PENDING,
            'next_retry_at' => now()->addSeconds($delaySeconds),
        ])->save();

        self::dispatch($delivery->id)->delay(now()->addSeconds($delaySeconds));
    }

    private function maybeAutoDisable(WebhookEndpoint $endpoint): void
    {
        if (! $endpoint->enabled) {
            return;
        }
        $cutoff = now()->subDays(WebhookEndpoint::AUTO_DISABLE_AFTER_DAYS);
        $hasDeliveriesInWindow = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->id)
            ->where('created_at', '>=', $cutoff)
            ->exists();
        if (! $hasDeliveriesInWindow) {
            return;
        }
        $hasNonFailureInWindow = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->id)
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('status', [WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_ABANDONED])
            ->exists();
        if ($hasNonFailureInWindow) {
            return;
        }

        $endpoint->forceFill(['enabled' => false, 'disabled_at' => now()])->save();
        Log::info('webhook_endpoint_auto_disabled', [
            'webhook_endpoint_id' => $endpoint->id,
            'reason' => sprintf('all-failure window of %d days reached', WebhookEndpoint::AUTO_DISABLE_AFTER_DAYS),
        ]);
    }
}
