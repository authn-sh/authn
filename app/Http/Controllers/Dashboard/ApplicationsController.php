<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\AuthorizationGrant;
use App\Models\OauthApplication;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class ApplicationsController
{
    use ResolvesDashboardEnv;

    public function applications(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $rows = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->whereNull('removed_at')
            ->orderBy('name')
            ->get();
        $grantCounts = AuthorizationGrant::query()->withoutGlobalScopes()
            ->whereIn('oauth_application_id', $rows->pluck('id'))
            ->whereNull('revoked_at')
            ->selectRaw('oauth_application_id, count(*) as c')
            ->groupBy('oauth_application_id')
            ->pluck('c', 'oauth_application_id');

        return Inertia::render('Dashboard/Applications', [
            'applications' => $rows->map(fn (OauthApplication $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'client_id' => $a->client_id,
                'callback_urls' => is_array($a->callback_urls) ? array_values($a->callback_urls) : [],
                'scopes' => is_array($a->scopes) ? array_values($a->scopes) : [],
                'is_public' => (bool) $a->is_public,
                'grants_count' => (int) ($grantCounts[$a->id] ?? 0),
                'created_at' => $a->created_at?->getTimestampMs(),
            ])->all(),
        ]);
    }

    public function newApplication(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        if ($this->env($project_slug, $env_slug) === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/NewApplication');
    }

    public function storeOauthApplication(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'callback_urls' => ['required', 'array', 'min:1'],
            'callback_urls.*' => ['required', 'string', 'max:2048'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'max:120'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
        $isPublic = (bool) ($data['is_public'] ?? false);
        $secret = $isPublic ? null : OauthApplication::mintClientSecret();

        $row = OauthApplication::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'name' => $data['name'],
            'hashed_client_secret' => $secret['hash'] ?? null,
            'callback_urls' => array_values($data['callback_urls']),
            'scopes' => array_values($data['scopes'] ?? []),
            'is_public' => $isPublic,
        ]);

        $redirect = redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications")
            ->with('oauth_application_saved', true)
            ->with('oauth_application_id', $row->id);
        if ($secret !== null) {
            $redirect = $redirect->with('oauth_application_secret', $secret['plaintext']);
        }

        return $redirect;
    }

    public function updateOauthApplication(Request $request, string $project_slug, string $env_slug, string $oauth_application_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($row === null) {
            return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications");
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'callback_urls' => ['sometimes', 'array', 'min:1'],
            'callback_urls.*' => ['required', 'string', 'max:2048'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'max:120'],
        ]);

        $row->fill(array_intersect_key($data, array_flip(['name', 'callback_urls', 'scopes'])));
        $row->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications")
            ->with('oauth_application_saved', true);
    }

    public function rotateOauthApplicationSecret(string $project_slug, string $env_slug, string $oauth_application_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($row === null || $row->is_public) {
            return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications");
        }

        $secret = OauthApplication::mintClientSecret();
        $row->forceFill(['hashed_client_secret' => $secret['hash']])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications")
            ->with('oauth_application_saved', true)
            ->with('oauth_application_id', $row->id)
            ->with('oauth_application_secret', $secret['plaintext']);
    }

    public function destroyOauthApplication(string $project_slug, string $env_slug, string $oauth_application_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($row !== null) {
            AuthorizationGrant::query()->withoutGlobalScopes()
                ->where('oauth_application_id', $row->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $row->delete();
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications")
            ->with('oauth_application_deleted', true);
    }
}
