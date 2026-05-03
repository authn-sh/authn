<?php

declare(strict_types=1);

use App\Http\Controllers\Fapi\ClientController;
use App\Http\Controllers\Fapi\EnvironmentController;
use App\Http\Controllers\Fapi\PingController;
use App\Http\Controllers\Fapi\SessionTokenController;
use App\Http\Controllers\WellKnown\JwksController;
use App\Http\Controllers\WellKnown\OpenIdConfigurationController;
use App\Http\Middleware\EnforceFapiOrigin;
use App\Http\Middleware\ResolveClientFromCookie;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Frontend API (FAPI) + Account Portal routes
|--------------------------------------------------------------------------
|
| Browser-facing. The route loader in bootstrap/app.php nests these:
|   subdomain mode — host = `*.{app_host}`, prefix per-group (`/v1`, ``)
|   path mode      — prefix = `/{env_slug}/v1` for FAPI,
|                              `/{env_slug}/account` for Account Portal
*/

// JWKS + OIDC discovery — sit at the FAPI host root, no /v1 prefix.
// These are public bootstrap endpoints, so they opt out of Origin
// enforcement (they don't carry credentials anyway).
Route::withoutMiddleware([EnforceFapiOrigin::class])->group(function (): void {
    Route::get('/.well-known/jwks.json', JwksController::class)->name('fapi.jwks');
    Route::get('/.well-known/openid-configuration', OpenIdConfigurationController::class)->name('fapi.openid_configuration');
});

Route::prefix('v1')->group(function (): void {
    Route::get('/_ping', PingController::class)->name('fapi.ping');

    // Public bootstrap endpoints — no Client cookie required. `show` mints
    // one on the fly when none is supplied. These also opt out of Origin
    // enforcement: GET /environment and GET /client are read-only (Origin
    // check skips automatically); PUT /client is the very first call, so
    // the SDK can't yet have a session worth protecting; handshake is
    // authenticated by the JWT it carries.
    Route::withoutMiddleware([EnforceFapiOrigin::class])->group(function (): void {
        Route::get('/environment', [EnvironmentController::class, 'show'])->name('fapi.environment');
        Route::get('/client', [ClientController::class, 'show'])->name('fapi.client.show');
        Route::put('/client', [ClientController::class, 'store'])->name('fapi.client.store');
        Route::delete('/client', [ClientController::class, 'destroy'])->name('fapi.client.destroy');
        Route::match(['get', 'post'], '/client/handshake', [ClientController::class, 'handshake'])->name('fapi.client.handshake');
    });

    // Routes that require an existing Client (resolved from the __client
    // cookie via ResolveClientFromCookie).
    Route::middleware(ResolveClientFromCookie::class)->group(function (): void {
        Route::post('/client/sessions/{sid}/tokens', SessionTokenController::class)->name('fapi.session_token');
        Route::post('/client/sessions/{sid}/tokens/{template}', SessionTokenController::class)->name('fapi.session_token.template');
    });
});

Route::prefix('account')->group(function (): void {
    Route::get('/_ping', PingController::class)->name('account_portal.ping');
});

// Catch-all OPTIONS preflight handler. The FapiCors middleware
// short-circuits the response with 204 + the right CORS headers
// when the Origin is in the env's allowlist; otherwise returns 204
// with no CORS headers (the browser then blocks the actual request).
Route::withoutMiddleware([EnforceFapiOrigin::class])
    ->options('{any}', fn () => response('', 204))
    ->where('any', '.*');
