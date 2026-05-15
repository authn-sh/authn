<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\Invitation;
use App\Models\Session;
use App\Models\User;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class OverviewController
{
    use ResolvesDashboardEnv;

    public function overview(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/Overview', [
            'counts' => [
                'users' => User::query()->withoutGlobalScopes()->where('environment_id', $env->id)->count(),
                'sessions_active' => Session::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->whereIn('status', Session::LIVE_STATUSES)->count(),
                'invitations_pending' => Invitation::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->where('status', Invitation::STATUS_PENDING)->count(),
            ],
        ]);
    }
}
