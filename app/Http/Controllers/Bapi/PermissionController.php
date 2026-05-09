<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Http\Resources\PermissionResource;
use App\Models\Environment;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only BAPI permissions surface — v0.2 only ships system-defined
 * permissions (seeded by AU-2). Operator-defined permissions are out of
 * scope; every Permission row carries `is_system: true`.
 *
 *   GET /v1/permissions
 */
final class PermissionController
{
    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = Permission::query()->withoutGlobalScopes()->where('environment_id', $env->id);
        if ($request->has('is_system')) {
            $query->where('is_system', $request->boolean('is_system'));
        }
        $query->orderBy('key');

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (Permission $p) => PermissionResource::from($p))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }
}
