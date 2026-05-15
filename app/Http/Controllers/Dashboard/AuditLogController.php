<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\WebhookEvent;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class AuditLogController
{
    use ResolvesDashboardEnv;

    public function auditLog(Request $request, string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $query = WebhookEvent::query()->withoutGlobalScopes()->where('environment_id', $env->id);
        $filter = (string) $request->input('type', '');
        if ($filter !== '') {
            $query->where('type', 'like', $filter.'%');
        }
        $entries = $query->latest('created_at')->limit(100)->get();

        return Inertia::render('Dashboard/AuditLog', [
            'note' => 'Operator audit feed: all webhook events for this environment. Full audit log lands in v0.8 (PLAN §15.5).',
            'filter' => $filter,
            'entries' => $entries->map(fn (WebhookEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'was_test' => (bool) $e->was_test,
                'data' => $e->data,
                'created_at' => $e->created_at?->getTimestampMs(),
            ])->all(),
        ]);
    }
}
