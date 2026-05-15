<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\ApiKey;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Keys\KeyGenerator;
use App\Support\RoutingLabel;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class DashboardHomeController
{
    use ResolvesDashboardEnv;

    public function __construct(
        private readonly KeyGenerator $keyGenerator,
    ) {}

    public function home(): InertiaResponse|RedirectResponse
    {
        $workspace = $this->workspace();
        if ($workspace === null) {
            return redirect(Url::dashboardPathPrefix().'/create-workspace');
        }
        $project = $this->firstProjectOutsideAdmin();
        $env = $project?->environments()->where('kind', Environment::KIND_PRODUCTION)->first()
            ?? $project?->environments()->first();
        if ($project === null || $env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return redirect(Url::dashboardPathPrefix()."/{$project->slug}/{$env->slug}/overview");
    }

    public function createWorkspace(): InertiaResponse
    {
        return Inertia::render('Dashboard/CreateWorkspace', []);
    }

    public function createProject(): InertiaResponse
    {
        return Inertia::render('Dashboard/CreateProject', []);
    }

    public function storeProject(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        if ($workspace === null) {
            return redirect(Url::dashboardPathPrefix().'/create-workspace');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'regex:/^[a-z0-9-]{3,40}$/',
                'not_in:_admin',
                Rule::unique('projects', 'slug')->where('owner_organization_id', $workspace->organization_id),
            ],
        ]);

        [$project, $env] = DB::transaction(function () use ($request, $workspace): array {
            $project = Project::query()->withoutGlobalScopes()->create([
                'name' => (string) $request->input('name'),
                'slug' => (string) $request->input('slug'),
                'owner_organization_id' => $workspace->organization_id,
            ]);
            $env = Environment::query()->withoutGlobalScopes()->create([
                'project_id' => $project->id,
                'kind' => Environment::KIND_PRODUCTION,
                'slug' => 'production',
                'routing_label' => RoutingLabel::generate(),
                'allowed_origins' => [],
            ]);
            ApiKey::query()->create([
                'environment_id' => $env->id,
                'kind' => ApiKey::KIND_PUBLISHABLE,
                'prefix' => 'pk_'.$env->keyEnvironmentSegment().'_',
                'hashed_secret' => $this->keyGenerator->hash($this->keyGenerator->publishableKey($env)),
                'name' => 'Default publishable key',
            ]);

            return [$project, $env];
        });

        return redirect(Url::dashboardPathPrefix()."/{$project->slug}/{$env->slug}/overview");
    }

    public function workspaceSettings(): InertiaResponse|RedirectResponse
    {
        $workspace = $this->workspace();
        if ($workspace === null) {
            return redirect(Url::dashboardPathPrefix().'/create-workspace');
        }

        return Inertia::render('Dashboard/WorkspaceSettings', [
            'membership' => [
                'organization_id' => $workspace->organization_id,
                'role' => $workspace->role?->key,
            ],
        ]);
    }

    private function firstProjectOutsideAdmin(): ?Project
    {
        return Project::query()
            ->withoutGlobalScopes()
            ->where('is_system', false)
            ->orderBy('created_at')
            ->first();
    }
}
