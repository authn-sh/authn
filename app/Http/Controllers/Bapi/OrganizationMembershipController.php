<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Events\Organizations\OrganizationMembershipCreated;
use App\Events\Organizations\OrganizationMembershipDeleted;
use App\Events\Organizations\OrganizationMembershipUpdated;
use App\Http\Requests\Bapi\Organizations\CreateMembershipRequest;
use App\Http\Requests\Bapi\Organizations\UpdateMembershipRequest;
use App\Http\Resources\OrganizationMembershipResource;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\RoleSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BAPI organization memberships surface (PLAN §3.1, OA-2).
 *
 *   GET    /v1/organizations/{organization_id}/memberships
 *   POST   /v1/organizations/{organization_id}/memberships
 *   PATCH  /v1/organizations/{organization_id}/memberships/{user_id}
 *   DELETE /v1/organizations/{organization_id}/memberships/{user_id}
 */
final class OrganizationMembershipController
{
    public function index(Request $request, string $organizationId): JsonResponse
    {
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $query = OrganizationMembership::query()->where('organization_id', $org->id);
        if ($request->has('role')) {
            $roles = (array) $request->input('role');
            $query->whereHas('role', fn ($q) => $q->whereIn('key', $roles));
        }
        if ($request->has('user_id')) {
            $query->whereIn('user_id', (array) $request->input('user_id'));
        }

        $orderBy = (string) $request->input('order_by', '-created_at');
        $direction = str_starts_with($orderBy, '-') ? 'desc' : 'asc';
        $column = ltrim($orderBy, '-+');
        $allowed = ['created_at', 'updated_at'];
        $query->orderBy(in_array($column, $allowed, true) ? $column : 'created_at', $direction);

        $limit = max(1, min(500, (int) $request->input('limit', 10)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->with(['user', 'role.permissions'])->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OrganizationMembership $m) => OrganizationMembershipResource::from($m, includePrivate: true))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(CreateMembershipRequest $request, string $organizationId): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $userId = (string) $request->input('user_id');
        $user = User::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $userId)
            ->first();
        if ($user === null) {
            return $this->error(422, 'form_param_value_invalid', 'user_id does not match a user in this environment.');
        }

        $role = $this->resolveRole($env, (string) $request->input('role'));
        if ($role === null) {
            return $this->error(422, 'form_param_value_invalid', 'role is not a known role key in this environment.');
        }

        $duplicate = OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'form_identifier_exists', 'User is already a member of this organization.');
        }

        if ($org->max_allowed_memberships !== null && $org->members_count >= $org->max_allowed_memberships) {
            return $this->error(422, 'organization_membership_quota_exceeded', 'Organization has reached its membership cap.');
        }

        $membership = DB::transaction(function () use ($env, $org, $userId, $role, $request): OrganizationMembership {
            $row = OrganizationMembership::create([
                'environment_id' => $env->id,
                'organization_id' => $org->id,
                'user_id' => $userId,
                'role_id' => $role->id,
                'public_metadata' => $request->input('public_metadata') ?? [],
                'private_metadata' => $request->input('private_metadata') ?? [],
            ]);
            // Counter cache. Keep it inline (per AU-1's note); a job-based
            // backfill is deferred.
            $org->increment('members_count');

            return $row;
        });

        OrganizationMembershipCreated::dispatch($membership);

        return response()->json(
            OrganizationMembershipResource::from($membership->fresh()->load(['user', 'role.permissions']), includePrivate: true),
            201,
        );
    }

    public function update(UpdateMembershipRequest $request, string $organizationId, string $userId): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->first();
        if ($membership === null) {
            return $this->error(404, 'organization_membership_not_found', 'No membership matches that user in this organization.');
        }

        $role = $this->resolveRole($env, (string) $request->input('role'));
        if ($role === null) {
            return $this->error(422, 'form_param_value_invalid', 'role is not a known role key in this environment.');
        }

        if (
            $membership->role?->key === RoleSeeder::ROLE_ADMIN
            && $role->key !== RoleSeeder::ROLE_ADMIN
            && $this->countAdmins($org) <= 1
        ) {
            return $this->error(422, 'organization_last_admin', 'Cannot demote the last admin of an organization.');
        }

        $membership->role_id = $role->id;
        if ($request->has('public_metadata') && is_array($request->input('public_metadata'))) {
            $membership->public_metadata = $request->input('public_metadata');
        }
        if ($request->has('private_metadata') && is_array($request->input('private_metadata'))) {
            $membership->private_metadata = $request->input('private_metadata');
        }
        $membership->save();

        OrganizationMembershipUpdated::dispatch($membership->fresh());

        return response()->json(
            OrganizationMembershipResource::from(
                $membership->fresh()->load(['user', 'role.permissions']),
                includePrivate: true,
            ),
        );
    }

    public function destroy(string $organizationId, string $userId): JsonResponse
    {
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->first();
        if ($membership === null) {
            return $this->error(404, 'organization_membership_not_found', 'No membership matches that user in this organization.');
        }

        if (
            $membership->role?->key === RoleSeeder::ROLE_ADMIN
            && $this->countAdmins($org) <= 1
        ) {
            return $this->error(422, 'organization_last_admin', 'Cannot remove the last admin of an organization.');
        }

        DB::transaction(function () use ($org, $membership): void {
            $membership->delete();
            $org->decrement('members_count');
        });

        OrganizationMembershipDeleted::dispatch($membership);

        return response()->json([
            'object' => 'deleted_object',
            'id' => $membership->id,
            'deleted' => true,
        ]);
    }

    /* -------------------- helpers -------------------- */

    private function findOrganization(string $id): ?Organization
    {
        $env = app(Environment::class);

        return Organization::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
    }

    private function resolveRole(Environment $env, string $key): ?Role
    {
        return Role::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('key', $key)
            ->first();
    }

    private function countAdmins(Organization $org): int
    {
        return OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->whereHas('role', fn ($q) => $q->where('key', RoleSeeder::ROLE_ADMIN))
            ->count();
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
