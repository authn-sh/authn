<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Support\Url;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configures Inertia for the Account Portal route group:
 *
 *   - Pins the root Blade view to `account-portal`.
 *   - Shares the env-derived bootstrap props (publishableKey, fapiUrl,
 *     appearance, localization, paths) so every page receives them
 *     without each controller re-assembling.
 *
 * Routes outside this group are untouched — the Dashboard (AU-17) will
 * register its own variant.
 */
final class HandleAccountPortalInertia
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::setRootView('account-portal');

        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        Inertia::share([
            'environment' => function () use ($env) {
                if (! $env instanceof Environment) {
                    return null;
                }

                return $this->bootstrapProps($env);
            },
        ]);

        return $next($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function bootstrapProps(Environment $env): array
    {
        $appearance = is_array($env->appearance) ? $env->appearance : [];
        $localization = is_array($env->localization) ? $env->localization : [];
        $paths = is_array($appearance['paths'] ?? null) ? $appearance['paths'] : [];

        // Account Portal pages live on the FAPI host; the URL helper picks
        // the right scheme/port and routing-mode prefix (and skips the slug
        // for the `_admin` env, which is mounted at the bare root).
        // `home_url` is the place a successful sign-in lands the user — for
        // tenants that's the customer's app, for the `_admin` env that's
        // the operator dashboard.
        $home = (string) ($appearance['home_url'] ?? $env->home_url ?? Url::accountPortal($env, '/'));

        return [
            'publishable_key' => 'pk_'.$env->keyEnvironmentSegment().'_'.substr($env->id, 4, 16),
            'fapi_url' => Url::fapi($env),
            'appearance' => $appearance,
            'localization' => array_merge([
                'default_locale' => 'en-US',
                'supported_locales' => ['en-US'],
                'fallback_locale' => 'en-US',
            ], $localization),
            'paths' => array_merge([
                'sign_in_url' => Url::accountPortal($env, '/sign-in'),
                'sign_up_url' => Url::accountPortal($env, '/sign-up'),
                'user_profile_url' => Url::accountPortal($env, '/user'),
                'after_sign_in_url' => $home,
                'after_sign_up_url' => $home,
                'after_sign_out_url' => $home,
            ], $paths),
        ];
    }
}
