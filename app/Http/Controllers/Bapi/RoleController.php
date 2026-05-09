<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Events\Organizations\RoleCreated;
use App\Events\Organizations\RoleDeleted;
use App\Events\Organizations\RolePermissionsChanged;
use App\Events\Organizations\RoleUpdated;
use App\Http\Requests\Bapi\Roles\CreateRoleRequest;
use App\Http\Requests\Bapi\Roles\SetPermissionsRequest;
use App\Http\Requests\Bapi\Roles\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Environment;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Tenancy\RoleSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BAPI roles surface (PLAN §4.5, OA-2). System rows seeded by AU-2 are
 * read-only here; operators can mint custom roles and bind any subset
 * of system permissions to them.
 *
 *   GET    /v1/roles
 *   POST   /v1/roles
 *   GET    /v1/roles/{role_id}
 *   PATCH  /v1/roles/{role_id}
 *   DELETE /v1/roles/{role_id}
 *   PUT    /v1/roles/{role_id}/permissions
 */
final class RoleController
{
    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = Role::query()->withoutGlobalScopes()->where('environment_id', $env->id);

        if ($request->has('is_system')) {
            $query->where('is_system', $request->boolean('is_system'));
        }
        $query->orderBy('is_system', 'desc')->orderBy('key');

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->with('permissions')->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (Role $r) => RoleResource::from($r))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(CreateRoleRequest $request): JsonResponse
    {
        $env = app(Environment::class);
        $key = (string) $request->input('key');

        if (in_array($key, [RoleSeeder::ROLE_ADMIN, RoleSeeder::ROLE_MEMBER], true)) {
            return $this->error(409, 'role_key_reserved', 'That role key is reserved for system roles.');
        }
        $duplicate = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('key', $key)
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'form_identifier_exists', 'A role with that key already exists in this environment.');
        }

        $permissionIds = $this->resolvePermissionIds($env, (array) $request->input('permissions', []));
        if ($permissionIds === false) {
            return $this->error(422, 'form_param_value_invalid', 'One or more permission keys are unknown in this environment.');
        }

        $role = DB::transaction(function () use ($env, $key, $request, $permissionIds): Role {
            $row = Role::create([
                'environment_id' => $env->id,
                'key' => $key,
                'name' => (string) $request->input('name'),
                'description' => $request->input('description'),
                'is_creator_eligible' => $request->boolean('is_creator_eligible', false),
                'is_default' => false,
                'is_system' => false,
            ]);
            if (! empty($permissionIds)) {
                $row->permissions()->sync($permissionIds);
            }

            return $row;
        });

        RoleCreated::dispatch($role->fresh());

        return response()->json(RoleResource::from($role->fresh()->load('permissions')), 201);
    }

    public function show(string $roleId): JsonResponse
    {
        $role = $this->find($roleId);
        if ($role === null) {
            return $this->error(404, 'role_not_found', 'No role matches that id in this environment.');
        }

        return response()->json(RoleResource::from($role->load('permissions')))
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdateRoleRequest $request, string $roleId): JsonResponse
    {
        $role = $this->find($roleId);
        if ($role === null) {
            return $this->error(404, 'role_not_found', 'No role matches that id in this environment.');
        }
        if ($role->is_system) {
            return $this->error(422, 'role_is_system', 'System roles are read-only.');
        }

        if ($request->has('name')) {
            $role->name = (string) $request->input('name');
        }
        if ($request->has('description')) {
            $role->description = $request->input('description');
        }
        if ($request->has('is_creator_eligible')) {
            $role->is_creator_eligible = $request->boolean('is_creator_eligible');
        }
        $role->save();

        RoleUpdated::dispatch($role->fresh());

        return response()->json(RoleResource::from($role->fresh()->load('permissions')));
    }

    public function destroy(string $roleId): JsonResponse
    {
        $env = app(Environment::class);
        $role = $this->find($roleId);
        if ($role === null) {
            return $this->error(404, 'role_not_found', 'No role matches that id in this environment.');
        }
        if ($role->is_system) {
            return $this->error(422, 'role_is_system', 'System roles cannot be deleted.');
        }
        if ($role->is_default) {
            return $this->error(422, 'role_is_default', 'The default role cannot be deleted (would orphan the default).');
        }

        $defaultRole = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('is_default', true)
            ->first();
        if ($defaultRole === null) {
            return $this->error(422, 'role_no_default', 'Environment has no default role to reassign memberships to.');
        }

        DB::transaction(function () use ($role, $defaultRole): void {
            OrganizationMembership::query()
                ->where('role_id', $role->id)
                ->update(['role_id' => $defaultRole->id]);
            $role->delete();
        });

        RoleDeleted::dispatch($role);

        return response()->json([
            'object' => 'deleted_object',
            'id' => $roleId,
            'deleted' => true,
        ]);
    }

    public function setPermissions(SetPermissionsRequest $request, string $roleId): JsonResponse
    {
        $env = app(Environment::class);
        $role = $this->find($roleId);
        if ($role === null) {
            return $this->error(404, 'role_not_found', 'No role matches that id in this environment.');
        }
        if ($role->is_system) {
            return $this->error(422, 'role_is_system', 'System roles are read-only.');
        }

        $permissionIds = $this->resolvePermissionIds($env, (array) $request->input('permissions', []));
        if ($permissionIds === false) {
            return $this->error(422, 'form_param_value_invalid', 'One or more permission keys are unknown in this environment.');
        }

        DB::transaction(function () use ($role, $permissionIds): void {
            $role->permissions()->sync($permissionIds);
        });

        $fresh = $role->fresh()->load('permissions');
        RolePermissionsChanged::dispatch($fresh, $fresh->permissions->pluck('key')->all());

        return response()->json(RoleResource::from($fresh));
    }

    /* -------------------- helpers -------------------- */

    private function find(string $id): ?Role
    {
        $env = app(Environment::class);

        return Role::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>|false list of Permission ids, or false when any key is unknown.
     */
    private function resolvePermissionIds(Environment $env, array $keys): array|false
    {
        if (empty($keys)) {
            return [];
        }
        $rows = Permission::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->whereIn('key', $keys)
            ->get();
        if ($rows->count() !== count(array_unique($keys))) {
            return false;
        }

        return $rows->pluck('id')->all();
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ], $status);
    }
}
