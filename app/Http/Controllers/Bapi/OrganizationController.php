<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Events\Organizations\OrganizationCreated;
use App\Events\Organizations\OrganizationDeleted;
use App\Events\Organizations\OrganizationUpdated;
use App\Http\Requests\Bapi\Organizations\CreateOrganizationRequest;
use App\Http\Requests\Bapi\Organizations\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * BAPI organizations surface (PLAN §3.1, OA-2).
 *
 *   GET    /v1/organizations
 *   POST   /v1/organizations
 *   GET    /v1/organizations/{id}
 *   PATCH  /v1/organizations/{id}
 *   DELETE /v1/organizations/{id}
 */
final class OrganizationController
{
    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = Organization::query()->withoutGlobalScopes()->where('environment_id', $env->id);

        if ($request->has('query')) {
            $needle = '%'.strtolower((string) $request->input('query')).'%';
            $query->where(function ($q) use ($needle): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$needle]);
            });
        }
        if ($request->has('user_id')) {
            $userIds = (array) $request->input('user_id');
            $query->whereHas('memberships', fn ($q) => $q->whereIn('user_id', $userIds));
        }

        $orderBy = (string) $request->input('order_by', '-created_at');
        $direction = str_starts_with($orderBy, '-') ? 'desc' : 'asc';
        $column = ltrim($orderBy, '-+');
        $allowed = ['created_at', 'updated_at', 'name', 'members_count'];
        $query->orderBy(in_array($column, $allowed, true) ? $column : 'created_at', $direction);

        $limit = max(1, min(500, (int) $request->input('limit', 10)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (Organization $o) => OrganizationResource::from($o, includePrivate: true))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(CreateOrganizationRequest $request): JsonResponse
    {
        $env = app(Environment::class);

        $createdBy = $request->input('created_by');
        if (is_string($createdBy) && $createdBy !== '') {
            $exists = User::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->where('id', $createdBy)
                ->exists();
            if (! $exists) {
                return $this->error(422, 'form_param_value_invalid', 'created_by user does not exist in this environment.');
            }
        }

        $name = (string) $request->input('name');
        $slug = $request->filled('slug') ? (string) $request->input('slug') : $this->slugify($name);

        $duplicate = Organization::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('slug', $slug)
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'form_identifier_exists', 'An organization with that slug already exists.');
        }

        $org = Organization::create([
            'environment_id' => $env->id,
            'name' => $name,
            'slug' => $slug,
            'created_by_user_id' => is_string($createdBy) && $createdBy !== '' ? $createdBy : null,
            'max_allowed_memberships' => $request->input('max_allowed_memberships'),
            'admin_delete_enabled' => $request->boolean('admin_delete_enabled', true),
            'public_metadata' => $request->input('public_metadata') ?? [],
            'private_metadata' => $request->input('private_metadata') ?? [],
        ]);

        OrganizationCreated::dispatch($org);

        return response()->json(OrganizationResource::from($org->fresh(), includePrivate: true), 201);
    }

    public function show(string $id): JsonResponse
    {
        $org = $this->find($id);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        return response()->json(OrganizationResource::from($org, includePrivate: true))
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdateOrganizationRequest $request, string $id): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->find($id);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        if ($request->has('slug')) {
            $newSlug = (string) $request->input('slug');
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
            }
            $org->slug = $newSlug;
        }
        if ($request->has('name')) {
            $org->name = (string) $request->input('name');
        }
        if ($request->has('max_allowed_memberships')) {
            $org->max_allowed_memberships = $request->input('max_allowed_memberships');
        }
        if ($request->has('admin_delete_enabled')) {
            $org->admin_delete_enabled = $request->boolean('admin_delete_enabled');
        }
        foreach (['public_metadata', 'private_metadata'] as $blob) {
            if ($request->has($blob) && is_array($request->input($blob))) {
                $org->{$blob} = $request->input($blob);
            }
        }
        $org->save();

        OrganizationUpdated::dispatch($org->fresh());

        return response()->json(OrganizationResource::from($org->fresh(), includePrivate: true));
    }

    public function destroy(string $id): JsonResponse
    {
        $org = $this->find($id);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }
        if (! $org->admin_delete_enabled) {
            return $this->error(422, 'organization_admin_delete_disabled', 'Admin delete is disabled for this organization.');
        }

        $copy = $org->replicate();
        $copy->id = $org->id;
        $copy->setRawAttributes($org->getAttributes(), sync: true);

        $org->delete();

        OrganizationDeleted::dispatch($copy);

        return response()->json([
            'object' => 'deleted_object',
            'id' => $id,
            'slug' => $copy->slug,
            'deleted' => true,
        ]);
    }

    /* -------------------- helpers -------------------- */

    private function find(string $id): ?Organization
    {
        $env = app(Environment::class);

        return Organization::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
    }

    private function slugify(string $name): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'org-'.Str::lower(Str::random(8));
        }

        return Str::limit($slug, 60, '');
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
