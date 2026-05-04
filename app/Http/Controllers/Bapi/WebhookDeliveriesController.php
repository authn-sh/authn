<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Jobs\Webhooks\DispatchWebhookDelivery;
use App\Models\Environment;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BAPI webhook-deliveries surface.
 *
 *   GET   /v1/webhooks/deliveries (filters: endpoint_id, event_id, event_type, succeeded, after, before)
 *   GET   /v1/webhooks/deliveries/{id}
 *   POST  /v1/webhooks/deliveries/{id}/replay  (creates a fresh pending delivery + dispatch)
 */
final class WebhookDeliveriesController
{
    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = WebhookDelivery::query()
            ->whereIn('webhook_endpoint_id', WebhookEndpoint::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->select('id'));

        if ($request->has('endpoint_id')) {
            $query->whereIn('webhook_endpoint_id', (array) $request->input('endpoint_id'));
        }
        if ($request->has('event_id')) {
            $query->whereIn('webhook_event_id', (array) $request->input('event_id'));
        }
        if ($request->has('event_type')) {
            $eventIds = WebhookEvent::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->whereIn('type', (array) $request->input('event_type'))
                ->pluck('id');
            $query->whereIn('webhook_event_id', $eventIds);
        }
        if ($request->has('succeeded')) {
            if ($request->boolean('succeeded')) {
                $query->where('status', WebhookDelivery::STATUS_SUCCEEDED);
            } else {
                $query->whereIn('status', [WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_ABANDONED]);
            }
        }
        if ($request->filled('after')) {
            $query->where('created_at', '>=', $request->input('after'));
        }
        if ($request->filled('before')) {
            $query->where('created_at', '<=', $request->input('before'));
        }
        $query->latest('created_at');

        $limit = max(1, min(500, (int) $request->input('limit', 25)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (WebhookDelivery $d) => $this->shape($d, includeBodies: false))->all(),
            'total_count' => $total,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $delivery = $this->find($id);
        if ($delivery === null) {
            return $this->error();
        }

        return response()->json($this->shape($delivery, includeBodies: true));
    }

    public function replay(string $id): JsonResponse
    {
        $delivery = $this->find($id);
        if ($delivery === null) {
            return $this->error();
        }
        $fresh = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'webhook_event_id' => $delivery->webhook_event_id,
            'attempt' => 0,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);
        DispatchWebhookDelivery::dispatch($fresh->id);

        return response()->json($this->shape($fresh->fresh(), includeBodies: false), 202);
    }

    private function find(string $id): ?WebhookDelivery
    {
        $env = app(Environment::class);

        return WebhookDelivery::query()
            ->where('id', $id)
            ->whereIn('webhook_endpoint_id', WebhookEndpoint::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->select('id'))
            ->first();
    }

    private function shape(WebhookDelivery $delivery, bool $includeBodies): array
    {
        $event = WebhookEvent::query()->withoutGlobalScopes()->where('id', $delivery->webhook_event_id)->first();

        $shape = [
            'object' => 'webhook_delivery',
            'id' => $delivery->id,
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'webhook_event_id' => $delivery->webhook_event_id,
            'event_type' => $event?->type,
            'attempt' => $delivery->attempt,
            'status' => $delivery->status,
            'response_status' => $delivery->response_status,
            'requested_at' => $delivery->requested_at?->getTimestampMs(),
            'completed_at' => $delivery->completed_at?->getTimestampMs(),
            'next_retry_at' => $delivery->next_retry_at?->getTimestampMs(),
            'created_at' => $delivery->created_at?->getTimestampMs(),
            'updated_at' => $delivery->updated_at?->getTimestampMs(),
        ];
        if ($includeBodies) {
            $shape['request_body'] = $event !== null
                ? json_encode([
                    'type' => $event->type,
                    'object' => 'event',
                    'data' => $event->data,
                    'was_test' => (bool) $event->was_test,
                    'timestamp' => $event->created_at->getTimestamp(),
                    'instance_id' => $event->environment_id,
                ])
                : null;
            $shape['response_body'] = $delivery->response_body;
            $shape['response_headers'] = $delivery->response_headers;
        }

        return $shape;
    }

    private function error(): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => 'webhook_delivery_not_found', 'message' => 'Not found.', 'long_message' => 'Not found.', 'meta' => []]],
            'trace_id' => null,
        ], 404);
    }
}
