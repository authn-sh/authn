<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class WebhooksController
{
    use ResolvesDashboardEnv;

    public function webhooks(string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $tab = in_array($tab, ['endpoints', 'deliveries'], true) ? $tab : 'endpoints';

        if ($tab === 'deliveries') {
            $endpointIds = WebhookEndpoint::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->pluck('id');

            return Inertia::render('Dashboard/Configure/Webhooks/Deliveries', [
                'deliveries' => WebhookDelivery::query()
                    ->whereIn('webhook_endpoint_id', $endpointIds)
                    ->latest('created_at')
                    ->limit(100)
                    ->get()
                    ->map(fn (WebhookDelivery $d) => [
                        'id' => $d->id,
                        'webhook_endpoint_id' => $d->webhook_endpoint_id,
                        'webhook_event_id' => $d->webhook_event_id,
                        'attempt' => $d->attempt,
                        'status' => $d->status,
                        'response_status' => $d->response_status,
                        'created_at' => $d->created_at?->getTimestampMs(),
                    ])->all(),
            ]);
        }

        return Inertia::render('Dashboard/Configure/Webhooks/Endpoints', [
            'endpoints' => WebhookEndpoint::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->latest('created_at')
                ->get()
                ->map(fn (WebhookEndpoint $e) => [
                    'id' => $e->id,
                    'url' => $e->url,
                    'enabled' => (bool) $e->enabled,
                    'enabled_event_types' => is_array($e->enabled_event_types) ? $e->enabled_event_types : [],
                    'signing_secret_prefix' => $e->secretPrefix(),
                    'rotation_window_expires_at' => $e->prior_signing_secret_expires_at?->getTimestampMs(),
                ])->all(),
        ]);
    }

    public function storeWebhook(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $request->validate([
            'url' => ['required', 'url'],
            'enabled_event_types' => ['nullable', 'array'],
        ]);
        $row = WebhookEndpoint::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'url' => (string) $request->input('url'),
            'signing_secret' => WebhookEndpoint::mintSecret(),
            'enabled_event_types' => $request->input('enabled_event_types', ['*']),
            'enabled' => true,
        ]);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/webhooks")
            ->with('signing_secret', $row->displaySecret());
    }
}
