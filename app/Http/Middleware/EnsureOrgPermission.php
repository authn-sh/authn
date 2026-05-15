<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FAPI permission gate: requires the bound User to hold a system
 * permission (e.g. `org:memberships:manage`) inside the Organization
 * named by the `organization_id` route parameter.
 *
 * Usage in route definitions:
 *
 *   Route::patch('/organizations/{organization_id}', ...)
 *       ->middleware(EnsureOrgPermission::class.':org:profile:manage');
 *
 * Resolution order:
 *   1. Resolve the Organization from the route param. Non-member callers
 *      get a 404 organization_not_found instead of a 403 to avoid
 *      leaking the existence of a private org (PLAN §4.4 privacy default).
 *   2. Run User::hasOrgPermission(); on miss return 403.
 *
 * Binds the resolved Organization into the container so controllers can
 * read it via `app(Organization::class)` without a second lookup.
 */
final class EnsureOrgPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        $user = app()->bound(User::class) ? app(User::class) : null;
        if (! $env instanceof Environment || ! $user instanceof User) {
            return $this->error(401, 'authentication_required', 'Authentication required.');
        }

        $organizationId = $request->route('organization_id');
        if (! is_string($organizationId) || $organizationId === '') {
            return $this->error(400, 'organization_id_required', 'Route is missing the organization_id parameter.');
        }

        $org = Organization::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $organizationId)
            ->first();

        if ($org === null || ! $user->memberships()->where('organization_id', $org->id)->exists()) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id.');
        }

        if (! $user->hasOrgPermission($permission, $org)) {
            return $this->error(403, 'authorization_invalid', 'You do not have permission to perform this action on this organization.');
        }

        app()->instance(Organization::class, $org);

        return $next($request);
    }

    private function error(int $status, string $code, string $message): Response
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
