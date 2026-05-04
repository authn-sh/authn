<?php

use App\Http\Middleware\AuthenticateBapiKey;
use App\Http\Middleware\EnforceFapiOrigin;
use App\Http\Middleware\FapiCors;
use App\Http\Middleware\HandleDashboardInertia;
use App\Http\Middleware\RequireAdminSession;
use App\Http\Middleware\ResolveProjectFromHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The three authn.sh surfaces dispatch by host (subdomain mode) or by
            // path prefix (path mode), driven by `config('authn.routing_mode')`.
            // Order matters: BAPI and Dashboard register first so their host /
            // prefix bindings win against the FAPI catch-all.
            $routingMode = (string) config('authn.routing_mode', 'subdomain');
            $bapiHost = (string) config('authn.bapi_host');
            $dashboardHost = (string) config('authn.dashboard_host');
            $appHost = (string) config('authn.app_host');

            // -------- BAPI --------
            $bapi = Route::middleware('bapi')->prefix('v1');
            if ($routingMode === 'subdomain') {
                $bapi->domain($bapiHost);
            } else {
                $bapi->prefix('api/v1');
            }
            $bapi->group(__DIR__.'/../routes/bapi.php');

            // -------- Dashboard --------
            $dashboard = Route::middleware('dashboard');
            if ($routingMode === 'subdomain') {
                $dashboard->domain($dashboardHost);
            } else {
                $dashboard->prefix('dashboard');
            }
            $dashboard->group(__DIR__.'/../routes/dashboard.php');

            // -------- FAPI + Account Portal --------
            // The reserved `_admin` env always lives at the bare app host
            // (subdomain mode) or root (path mode) so the operator signs
            // in at https://<APP_HOST>/sign-in regardless of routing mode.
            // Tenant envs use the per-routing-mode pattern below.
            //
            // Both mounts load the same routes/fapi.php; ResolveProjectFromHost
            // detects which one served the request and binds the right
            // Environment.
            // Each mount namespaces its route names (`admin.` / `tenant.`)
            // so `php artisan route:cache` doesn't trip on duplicate names
            // when the same routes/fapi.php is included twice.
            if ($routingMode === 'subdomain') {
                Route::middleware('fapi')
                    ->domain($appHost)
                    ->name('admin.')
                    ->group(__DIR__.'/../routes/fapi.php');
                Route::middleware('fapi')
                    ->domain('{env_slug}.'.$appHost)
                    ->name('tenant.')
                    ->group(__DIR__.'/../routes/fapi.php');
            } else {
                Route::middleware('fapi')
                    ->name('admin.')
                    ->group(__DIR__.'/../routes/fapi.php');
                Route::middleware('fapi')
                    ->prefix('{env_slug}')
                    ->name('tenant.')
                    ->group(__DIR__.'/../routes/fapi.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The `__client` cookie is signed by us (HMAC of the row's
        // cookie_secret) and read raw by ResolveClientFromCookie — Laravel's
        // cookie encryption layer would corrupt it.
        $middleware->encryptCookies(except: ['__client']);

        $middleware->group('bapi', [
            AuthenticateBapiKey::class,
        ]);

        $middleware->group('dashboard', [
            RequireAdminSession::class,
            HandleDashboardInertia::class,
        ]);

        $middleware->group('fapi', [
            ResolveProjectFromHost::class,
            FapiCors::class,
            EnforceFapiOrigin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
