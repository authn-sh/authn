<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use App\Support\Url;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins Inertia's root view to `dashboard` and shares the operator + active
 * workspace + active project + active env into every Dashboard page.
 *
 * Active project / env are resolved from the URL params (`project_slug`,
 * `env_slug`) when present.
 */
final class HandleDashboardInertia
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::setRootView('dashboard');

        $operator = app()->bound(User::class) ? app(User::class) : null;
        $workspace = app()->bound('dashboard.workspace') ? app('dashboard.workspace') : null;
        $adminEnv = app()->bound(Environment::class) ? app(Environment::class) : null;

        $projectSlug = $request->route('project_slug');
        $envSlug = $request->route('env_slug');

        $activeProject = null;
        $activeEnv = null;
        if (is_string($projectSlug) && $projectSlug !== '') {
            $activeProject = Project::query()
                ->withoutGlobalScopes()
                ->where('slug', $projectSlug)
                ->first();
        }
        if ($activeProject !== null && is_string($envSlug) && $envSlug !== '') {
            $activeEnv = $activeProject->environments()->where('slug', $envSlug)->first();
        }

        Inertia::share([
            'operator' => $operator !== null ? [
                'id' => $operator->id,
                'name' => trim((string) ($operator->first_name.' '.$operator->last_name)) ?: 'Operator',
                'first_name' => $operator->first_name,
                'last_name' => $operator->last_name,
                'image_url' => $operator->image_url,
            ] : null,
            'workspace' => $workspace instanceof OrganizationMembership ? [
                'id' => $workspace->organization_id,
                'role' => $workspace->role,
            ] : null,
            'sign_in_url' => $adminEnv !== null ? Url::fapi($adminEnv, '/sign-in') : null,
            'active_project' => $activeProject !== null ? [
                'id' => $activeProject->id,
                'slug' => $activeProject->slug,
                'name' => $activeProject->name,
            ] : null,
            'active_environment' => $activeEnv !== null ? [
                'id' => $activeEnv->id,
                'slug' => $activeEnv->slug,
                'kind' => $activeEnv->kind,
                'frontend_api_host' => $activeEnv->frontend_api_host,
            ] : null,
        ]);

        return $next($request);
    }
}
