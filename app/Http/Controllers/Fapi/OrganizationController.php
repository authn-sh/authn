<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Events\Organizations\OrganizationCreated;
use App\Events\Organizations\OrganizationDeleted;
use App\Events\Organizations\OrganizationMembershipCreated;
use App\Events\Organizations\OrganizationMembershipDeleted;
use App\Events\Organizations\OrganizationUpdated;
use App\Http\Resources\ClientResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\RoleSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FAPI organization surface (PLAN §4.4, OA-3). End-user-facing CRUD scoped
 * to the authenticated session.
 *
 *   POST   /v1/organizations
 *   GET    /v1/organizations/{organization_id}            (member only)
 *   PATCH  /v1/organizations/{organization_id}            (org:sys_profile:manage)
 *   DELETE /v1/organizations/{organization_id}            (org:sys_profile:delete)
 *   POST   /v1/organizations/{organization_id}/leave
 */
final class OrganizationController
{
    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $user = app(User::class);

        $orgSettings = $this->organizationSettings($env);
        if (($orgSettings['allow_user_created_organizations'] ?? true) === false) {
            return $this->error(403, 'organization_creation_disabled', 'User-created organizations are disabled in this environment.');
        }
        $maxPerUser = $orgSettings['max_organizations_per_user'] ?? null;
        if (is_int($maxPerUser) && $maxPerUser >= 0) {
            $owned = $user->memberships()
                ->whereHas('role', fn ($q) => $q->where('key', RoleSeeder::ROLE_ADMIN))
                ->count();
            if ($owned >= $maxPerUser) {
                return $this->error(422, 'organization_quota_exceeded', 'You have reached the maximum number of organizations.');
            }
        }

        $name = $request->input('name');
        if (! is_string($name) || $name === '') {
            return $this->error(422, 'form_param_nil', 'name is required.');
        }
        $slug = $request->filled('slug') ? (string) $request->input('slug') : $this->slugify($name);
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $slug)) {
            return $this->error(422, 'form_param_format_invalid', 'slug must be lowercase alphanumeric with dashes.');
        }

        $duplicate = Organization::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('slug', $slug)
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'form_identifier_exists', 'An organization with that slug already exists.');
        }

        $creatorRoleKey = $orgSettings['creator_role'] ?? RoleSeeder::ROLE_ADMIN;
        $creatorRole = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('key', $creatorRoleKey)
            ->first();
        if ($creatorRole === null) {
            return $this->error(422, 'organization_creator_role_missing', "The configured creator_role `{$creatorRoleKey}` is not seeded.");
        }

        [$org, $membership] = DB::transaction(function () use ($env, $user, $name, $slug, $request, $creatorRole): array {
            $org = Organization::create([
                'environment_id' => $env->id,
                'name' => $name,
                'slug' => $slug,
                'created_by_user_id' => $user->id,
                'public_metadata' => $request->input('public_metadata') ?? [],
                'private_metadata' => $request->input('private_metadata') ?? [],
            ]);
            $membership = OrganizationMembership::create([
                'environment_id' => $env->id,
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'role_id' => $creatorRole->id,
            ]);
            $org->increment('members_count');

            return [$org->fresh(), $membership];
        });

        OrganizationCreated::dispatch($org);
        OrganizationMembershipCreated::dispatch($membership);

        return $this->envelope(OrganizationResource::from($org), 201);
    }

    public function show(): JsonResponse
    {
        // EnsureOrgPermission(:org:sys_profile:read) already gated this and bound Organization.
        $org = app(Organization::class);

        return response()->json(OrganizationResource::from($org))
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $org = app(Organization::class);
        $env = app(Environment::class);

        if ($request->has('name') && is_string($request->input('name'))) {
            $org->name = (string) $request->input('name');
        }
        if ($request->has('slug')) {
            $newSlug = (string) $request->input('slug');
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $newSlug)) {
                return $this->error(422, 'form_param_format_invalid', 'slug must be lowercase alphanumeric with dashes.');
            }
            if ($newSlug !== $org->slug) {
                $duplicate = Organization::query()
                    ->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->where('slug', $newSlug)
                    ->where('id', '!=', $org->id)
                    ->exists();
                if ($duplicate) {
                    return $this->error(409, 'form_identifier_exists', 'An organization with that slug already exists.');
                }
                $org->slug = $newSlug;
            }
        }
        if ($request->has('public_metadata') && is_array($request->input('public_metadata'))) {
            $org->public_metadata = $request->input('public_metadata');
        }
        $org->save();

        OrganizationUpdated::dispatch($org->fresh());

        return $this->envelope(OrganizationResource::from($org->fresh()));
    }

    public function destroy(): JsonResponse
    {
        $org = app(Organization::class);
        if (! $org->admin_delete_enabled) {
            return $this->error(422, 'organization_admin_delete_disabled', 'Admin delete is disabled for this organization.');
        }

        $copy = $org->replicate();
        $copy->setRawAttributes($org->getAttributes(), sync: true);
        $org->delete();

        OrganizationDeleted::dispatch($copy);

        return $this->envelope([
            'object' => 'deleted_object',
            'id' => $org->id,
            'deleted' => true,
        ]);
    }

    public function leave(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $user = app(User::class);
        $organizationId = (string) $request->route('organization_id');

        $org = Organization::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $organizationId)
            ->first();
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id.');
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $user->id)
            ->first();
        if ($membership === null) {
            return $this->error(404, 'organization_membership_not_found', 'You are not a member of this organization.');
        }

        if (
            $membership->role?->key === RoleSeeder::ROLE_ADMIN
            && $this->countAdmins($org) <= 1
        ) {
            return $this->error(422, 'organization_last_admin', 'Cannot leave as the last admin of an organization.');
        }

        DB::transaction(function () use ($org, $membership): void {
            $membership->delete();
            $org->decrement('members_count');
        });

        OrganizationMembershipDeleted::dispatch($membership);

        return $this->envelope([
            'object' => 'deleted_object',
            'id' => $membership->id,
            'deleted' => true,
        ]);
    }

    /* -------------------- helpers -------------------- */

    private function organizationSettings(Environment $env): array
    {
        $settings = is_array($env->user_settings) ? $env->user_settings : [];
        $org = $settings['organization_settings'] ?? [];

        return is_array($org) ? $org : [];
    }

    private function countAdmins(Organization $org): int
    {
        return OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->whereHas('role', fn ($q) => $q->where('key', RoleSeeder::ROLE_ADMIN))
            ->count();
    }

    private function slugify(string $name): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'org-'.Str::lower(Str::random(8));
        }

        return Str::limit($slug, 60, '');
    }

    private function envelope(mixed $response, int $status = 200): JsonResponse
    {
        $client = app()->bound(Client::class) ? app(Client::class) : null;

        return response()->json([
            'response' => $response,
            'client' => ClientResource::from($client?->fresh()),
        ], $status)->header('Cache-Control', 'no-store');
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
