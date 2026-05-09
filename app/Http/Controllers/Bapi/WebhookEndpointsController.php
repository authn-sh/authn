<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Models\Environment;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BAPI webhook-endpoints surface.
 *
 *   GET    /v1/webhooks/endpoints
 *   POST   /v1/webhooks/endpoints                     (returns full secret once)
 *   GET    /v1/webhooks/endpoints/{id}
 *   PATCH  /v1/webhooks/endpoints/{id}
 *   DELETE /v1/webhooks/endpoints/{id}
 *   POST   /v1/webhooks/endpoints/{id}/rotate_secret  (returns new secret once)
 */
final class WebhookEndpointsController
{
    public function index(): JsonResponse
    {
        $env = app(Environment::class);
        $rows = WebhookEndpoint::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->latest('created_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (WebhookEndpoint $e) => $this->shape($e, includeSecret: false))->all(),
            'total_count' => $rows->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $request->validate([
            'url' => ['required', 'url'],
            'enabled_event_types' => ['nullable', 'array'],
            'enabled_event_types.*' => ['string'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $row = WebhookEndpoint::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'url' => (string) $request->input('url'),
            'signing_secret' => WebhookEndpoint::mintSecret(),
            'enabled_event_types' => $request->input('enabled_event_types', ['*']),
            'enabled' => $request->boolean('enabled', true),
        ]);

        return response()->json($this->shape($row, includeSecret: true), 201);
    }

    public function show(string $id): JsonResponse
    {
        $row = $this->find($id);
        if ($row === null) {
            return $this->error();
        }

        return response()->json($this->shape($row, includeSecret: false));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = $this->find($id);
        if ($row === null) {
            return $this->error();
        }
        $request->validate([
            'url' => ['nullable', 'url'],
            'enabled_event_types' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        if ($request->has('url')) {
            $row->url = (string) $request->input('url');
        }
        if ($request->has('enabled_event_types')) {
            $row->enabled_event_types = (array) $request->input('enabled_event_types');
        }
        if ($request->has('enabled')) {
            $row->enabled = $request->boolean('enabled');
            if ($row->enabled) {
                $row->disabled_at = null;
            }
        }
        $row->save();

        return response()->json($this->shape($row->fresh(), includeSecret: false));
    }

    public function destroy(string $id): JsonResponse
    {
        $row = $this->find($id);
        if ($row === null) {
            return $this->error();
        }
        $row->delete();

        return response()->json(['object' => 'deleted_object', 'id' => $id, 'deleted' => true]);
    }

    public function rotateSecret(string $id): JsonResponse
    {
        $row = $this->find($id);
        if ($row === null) {
            return $this->error();
        }
        $newSecret = WebhookEndpoint::mintSecret();
        $row->forceFill([
            'prior_signing_secret' => $row->signing_secret,
            'prior_signing_secret_expires_at' => now()->addSeconds(WebhookEndpoint::ROTATION_WINDOW_SECONDS),
            'signing_secret' => $newSecret,
        ])->save();

        return response()->json($this->shape($row->fresh(), includeSecret: true));
    }

    private function find(string $id): ?WebhookEndpoint
    {
        $env = app(Environment::class);

        return WebhookEndpoint::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
    }

    private function shape(WebhookEndpoint $row, bool $includeSecret): array
    {
        $shape = [
            'object' => 'webhook_endpoint',
            'id' => $row->id,
            'url' => $row->url,
            'enabled' => (bool) $row->enabled,
            'enabled_event_types' => is_array($row->enabled_event_types) ? $row->enabled_event_types : [],
            'signing_secret_prefix' => $row->secretPrefix(),
            'rotation_window_expires_at' => $row->prior_signing_secret_expires_at?->getTimestampMs(),
            'disabled_at' => $row->disabled_at?->getTimestampMs(),
            'created_at' => $row->created_at?->getTimestampMs(),
            'updated_at' => $row->updated_at?->getTimestampMs(),
        ];
        if ($includeSecret) {
            $shape['signing_secret'] = $row->displaySecret();
        }

        return $shape;
    }

    private function error(): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => 'webhook_endpoint_not_found', 'message' => 'Not found.', 'long_message' => 'Not found.', 'meta' => []]],
            'trace_id' => null,
        ], 404);
    }
}
