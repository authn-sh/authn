<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class DomainsController
{
    use ResolvesDashboardEnv;

    public function domains(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        if ($this->env($project_slug, $env_slug) === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/Domains', []);
    }
}
