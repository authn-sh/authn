<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Models\Environment;
use App\Models\RedirectUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *   GET    /v1/redirect-urls
 *   POST   /v1/redirect-urls
 *   GET    /v1/redirect-urls/{id}
 *   DELETE /v1/redirect-urls/{id}
 */
final class RedirectUrlsController
{
    public function index(): JsonResponse
    {
        $env = app(Environment::class);
        $rows = RedirectUrl::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->latest('created_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (RedirectUrl $r) => $this->shape($r))->all(),
            'total_count' => $rows->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $request->validate(['url' => ['required', 'url', 'max:2048']]);
        $row = RedirectUrl::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'url' => (string) $request->input('url'),
        ]);

        return response()->json($this->shape($row), 201);
    }

    public function show(string $id): JsonResponse
    {
        $row = $this->find($id);
        if ($row === null) {
            return $this->error();
        }

        return response()->json($this->shape($row));
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

    private function find(string $id): ?RedirectUrl
    {
        $env = app(Environment::class);

        return RedirectUrl::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
    }

    private function shape(RedirectUrl $row): array
    {
        return [
            'object' => 'redirect_url',
            'id' => $row->id,
            'url' => $row->url,
            'created_at' => $row->created_at?->getTimestampMs(),
            'updated_at' => $row->updated_at?->getTimestampMs(),
        ];
    }

    private function error(): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => 'redirect_url_not_found', 'message' => 'Not found.', 'long_message' => 'Not found.', 'meta' => []]],
            'trace_id' => null,
        ], 404);
    }
}
