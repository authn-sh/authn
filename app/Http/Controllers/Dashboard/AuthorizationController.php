<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class AuthorizationController
{
    use ResolvesDashboardEnv;

    public function authorization(string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        $tab = in_array($tab, ['roles', 'permissions'], true) ? $tab : 'roles';
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        if ($tab === 'permissions') {
            return Inertia::render('Dashboard/Configure/Authorization/Permissions', [
                'permissions' => Permission::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->orderBy('key')
                    ->get()
                    ->map(fn (Permission $p) => [
                        'id' => $p->id,
                        'key' => $p->key,
                        'name' => $p->name,
                        'description' => $p->description,
                        'is_system' => (bool) $p->is_system,
                    ])->all(),
            ]);
        }

        return Inertia::render('Dashboard/Configure/Authorization/Roles', [
            'roles' => Role::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->with('permissions')
                ->orderBy('is_system', 'desc')
                ->orderBy('key')
                ->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id,
                    'key' => $r->key,
                    'name' => $r->name,
                    'description' => $r->description,
                    'is_system' => (bool) $r->is_system,
                    'is_default' => (bool) $r->is_default,
                    'is_creator_eligible' => (bool) $r->is_creator_eligible,
                    'permissions' => $r->permissions->pluck('key')->all(),
                ])->all(),
        ]);
    }
}
