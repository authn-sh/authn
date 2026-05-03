<?php

declare(strict_types=1);

use App\Http\Controllers\Fapi\PingController;
use App\Http\Controllers\Fapi\SessionTokenController;
use App\Http\Controllers\WellKnown\JwksController;
use App\Http\Controllers\WellKnown\OpenIdConfigurationController;
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
Route::get('/.well-known/jwks.json', JwksController::class)->name('fapi.jwks');
Route::get('/.well-known/openid-configuration', OpenIdConfigurationController::class)->name('fapi.openid_configuration');

Route::prefix('v1')->group(function (): void {
    Route::get('/_ping', PingController::class)->name('fapi.ping');

    // Routes that require an existing Client (resolved from the __client
    // cookie). The `GET /v1/client` endpoint that mints one on first
    // contact is intentionally NOT inside this group — it lands in AU-8.
    Route::middleware(ResolveClientFromCookie::class)->group(function (): void {
        Route::post('/client/sessions/{sid}/tokens', SessionTokenController::class)->name('fapi.session_token');
        Route::post('/client/sessions/{sid}/tokens/{template}', SessionTokenController::class)->name('fapi.session_token.template');
    });
});

Route::prefix('account')->group(function (): void {
    Route::get('/_ping', PingController::class)->name('account_portal.ping');
});
