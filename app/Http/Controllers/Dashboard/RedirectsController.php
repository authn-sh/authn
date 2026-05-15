<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Settings\RedirectsSettings;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class RedirectsController
{
    use ResolvesDashboardEnv;

    public function redirects(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/Redirects', [
            'redirects' => RedirectsSettings::fromUserSettings($env->user_settings)->toArray(),
            'allowed_origins' => is_array($env->allowed_origins) ? $env->allowed_origins : [],
        ]);
    }

    public function updateRedirects(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $rule = ['nullable', 'url:http,https', 'max:'.RedirectsSettings::MAX_URL_LENGTH];
        $request->validate([
            'after_sign_up' => $rule,
            'after_sign_in' => $rule,
            'home' => $rule,
            'after_create_organization' => $rule,
            'after_leave_organization' => $rule,
        ]);

        $allowedOrigins = is_array($env->allowed_origins) ? $env->allowed_origins : [];
        foreach (['after_sign_up', 'after_sign_in', 'home', 'after_create_organization', 'after_leave_organization'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '' && ! $this->originAllowed($value, $allowedOrigins)) {
                return back(303)->withErrors([$field => "URL origin is not in this environment's allowed_origins."]);
            }
        }

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $previous = RedirectsSettings::fromUserSettings($userSettings);
        $next = $previous->withPatch($request->only([
            'after_sign_up', 'after_sign_in', 'home', 'after_create_organization', 'after_leave_organization',
        ]));
        $userSettings['redirects'] = $next->toArray();
        $env->forceFill(['user_settings' => $userSettings])->save();

        return back(303)->with('redirects_saved', true);
    }

    /**
     * @param  list<string>  $allowedOrigins
     */
    private function originAllowed(string $url, array $allowedOrigins): bool
    {
        if ($allowedOrigins === []) {
            return true; // env has no CORS allowlist yet — accept anything.
        }
        $parsed = parse_url($url);
        if (! is_array($parsed) || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }
        $origin = $parsed['scheme'].'://'.$parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':'.$parsed['port'];
        }

        return in_array($origin, $allowedOrigins, true);
    }
}
