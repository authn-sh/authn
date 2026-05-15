<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\ApiKey;
use App\Services\Keys\KeyGenerator;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class ApiKeysController
{
    use ResolvesDashboardEnv;

    public function __construct(
        private readonly KeyGenerator $keyGenerator,
    ) {}

    public function apiKeys(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/ApiKeys', [
            'keys' => ApiKey::query()
                ->where('environment_id', $env->id)
                ->latest('created_at')
                ->get(['id', 'kind', 'prefix', 'name', 'last_used_at', 'revoked_at'])
                ->map(fn (ApiKey $k) => [
                    'id' => $k->id,
                    'kind' => $k->kind,
                    'prefix' => $k->prefix,
                    'name' => $k->name,
                    'last_used_at' => $k->last_used_at?->getTimestampMs(),
                    'revoked_at' => $k->revoked_at?->getTimestampMs(),
                ])->all(),
        ]);
    }

    public function rotateApiKey(string $project_slug, string $env_slug, string $id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $existing = ApiKey::query()->where('environment_id', $env->id)->where('id', $id)->first();
        if ($existing === null) {
            return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/api-keys");
        }

        $plaintext = $existing->kind === ApiKey::KIND_SECRET
            ? $this->keyGenerator->secretKey($env)
            : $this->keyGenerator->publishableKey($env);
        $existing->forceFill([
            'prefix' => substr($plaintext, 0, 16),
            'hashed_secret' => $this->keyGenerator->hash($plaintext),
            'last_used_at' => null,
        ])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/api-keys")
            ->with('rotated_secret', $plaintext);
    }
}
