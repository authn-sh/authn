<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Models\AllowlistIdentifier;
use App\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *   GET    /v1/allowlist-identifiers
 *   POST   /v1/allowlist-identifiers
 *   DELETE /v1/allowlist-identifiers/{id}
 */
final class AllowlistIdentifiersController
{
    public function index(): JsonResponse
    {
        $env = app(Environment::class);
        $rows = AllowlistIdentifier::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->latest('created_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (AllowlistIdentifier $r) => $this->shape($r))->all(),
            'total_count' => $rows->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'notify' => ['nullable', 'boolean'],
        ]);
        $identifier = strtolower((string) $request->input('identifier'));
        $type = str_starts_with($identifier, '@') || str_starts_with($identifier, '*@')
            ? AllowlistIdentifier::TYPE_EMAIL_DOMAIN
            : AllowlistIdentifier::TYPE_EMAIL_ADDRESS;
        $row = AllowlistIdentifier::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'identifier' => $identifier,
            'identifier_type' => $type,
            'notify' => $request->boolean('notify'),
        ]);

        return response()->json($this->shape($row), 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $env = app(Environment::class);
        $row = AllowlistIdentifier::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
        if ($row === null) {
            return response()->json([
                'errors' => [['code' => 'allowlist_identifier_not_found', 'message' => 'Not found.', 'long_message' => 'Not found.', 'meta' => []]],
                'trace_id' => null,
            ], 404);
        }
        $row->delete();

        return response()->json(['object' => 'deleted_object', 'id' => $id, 'deleted' => true]);
    }

    private function shape(AllowlistIdentifier $row): array
    {
        return [
            'object' => 'allowlist_identifier',
            'id' => $row->id,
            'identifier' => $row->identifier,
            'notify' => (bool) $row->notify,
            'created_at' => $row->created_at?->getTimestampMs(),
            'updated_at' => $row->updated_at?->getTimestampMs(),
        ];
    }
}
