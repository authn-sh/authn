<?php

declare(strict_types=1);

use App\Http\Controllers\Fapi\PingController;
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
|
| Real endpoints land in AU-8 / AU-9 / AU-10 / AU-11 / AU-12 (FAPI) and
| AU-16 (Account Portal). The placeholders below confirm the route group
| is wired up end-to-end.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/_ping', PingController::class)->name('fapi.ping');
});

Route::prefix('account')->group(function (): void {
    Route::get('/_ping', PingController::class)->name('account_portal.ping');
});
