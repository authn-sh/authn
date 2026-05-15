<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Localization\CanonicalSchema;
use App\Models\Environment;
use App\Support\Url;
use App\Webhooks\Emitter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AppearanceController
{
    use ResolvesDashboardEnv;

    public function updateAppearance(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'variables' => ['sometimes', 'array'],
            'elements' => ['sometimes', 'array'],
            'layout' => ['sometimes', 'array'],
        ]);

        $before = Environment::defaultAppearance();
        $stored = is_array($env->appearance) ? $env->appearance : [];
        foreach (['variables', 'elements', 'layout'] as $axis) {
            if (isset($stored[$axis]) && is_array($stored[$axis])) {
                $before[$axis] = $stored[$axis];
            }
        }

        $next = Environment::defaultAppearance();
        foreach (['variables', 'elements', 'layout'] as $axis) {
            if (is_array($request->input($axis))) {
                $next[$axis] = array_filter(
                    $request->input($axis),
                    static fn ($v) => is_string($v) || is_bool($v) || is_int($v) || is_float($v),
                );
            }
        }

        $env->forceFill(['appearance' => $next])->save();

        if ($before != $next) {
            app(Emitter::class)->emit(
                'instance.config.appearance_updated',
                [
                    'environment_id' => $env->id,
                    'previous' => $before,
                    'current' => $next,
                ],
                $env,
            );
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/appearance")
            ->with('appearance_saved', true);
    }

    public function updateLocalization(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'default_locale' => ['required', 'string'],
            'fallback_locale' => ['required', 'string'],
            'supported_locales' => ['required', 'array'],
            'supported_locales.*' => ['string'],
            'overrides' => ['sometimes', 'array'],
            'overrides.*' => ['array'],
        ]);

        $supported = array_values(array_map('strval', (array) $request->input('supported_locales', [])));
        $defaultLocale = (string) $request->input('default_locale');
        $fallbackLocale = (string) $request->input('fallback_locale');

        if (! in_array($defaultLocale, $supported, true) || ! in_array($fallbackLocale, $supported, true)) {
            return back()->withErrors([
                'supported_locales' => 'default_locale and fallback_locale must be in supported_locales.',
            ]);
        }
        $canonical = CanonicalSchema::keys();
        $rawOverrides = is_array($request->input('overrides')) ? (array) $request->input('overrides') : [];
        $overrides = [];
        $unknownKeys = [];
        foreach ($rawOverrides as $locale => $entries) {
            if (! is_string($locale) || ! is_array($entries)) {
                continue;
            }
            $clean = [];
            foreach ($entries as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    continue;
                }
                if (! in_array($key, $canonical, true)) {
                    $unknownKeys[] = "{$locale}.{$key}";

                    continue;
                }
                $clean[$key] = $value;
            }
            $overrides[$locale] = $clean;
        }

        if ($unknownKeys !== []) {
            return back()->withErrors([
                'overrides' => 'Unknown localization keys: '.implode(', ', $unknownKeys),
            ]);
        }

        $env->forceFill([
            'localization' => [
                'default_locale' => $defaultLocale,
                'fallback_locale' => $fallbackLocale,
                'supported_locales' => $supported,
                'overrides' => $overrides,
            ],
        ])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/localization")
            ->with('localization_saved', true);
    }
}
