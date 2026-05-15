<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\AllowlistIdentifier;
use App\Models\BlocklistIdentifier;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class RestrictionsController
{
    use ResolvesDashboardEnv;

    public function restrictions(string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $tab = in_array($tab, ['allowlist', 'blocklist'], true) ? $tab : 'allowlist';

        if ($tab === 'blocklist') {
            return Inertia::render('Dashboard/Configure/Restrictions/Blocklist', [
                'rows' => BlocklistIdentifier::query()->withoutGlobalScopes()->where('environment_id', $env->id)->get()
                    ->map(fn (BlocklistIdentifier $r) => ['id' => $r->id, 'identifier' => $r->identifier])->all(),
            ]);
        }

        return Inertia::render('Dashboard/Configure/Restrictions/Allowlist', [
            'rows' => AllowlistIdentifier::query()->withoutGlobalScopes()->where('environment_id', $env->id)->get()
                ->map(fn (AllowlistIdentifier $r) => ['id' => $r->id, 'identifier' => $r->identifier, 'notify' => (bool) $r->notify])->all(),
        ]);
    }
}
