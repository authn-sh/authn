<?php

declare(strict_types=1);

use App\Http\Controllers\Bapi\PingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Backend API (BAPI) routes
|--------------------------------------------------------------------------
|
| Server-to-server endpoints. Authenticated by `Authorization: Bearer sk_…`.
|
| The route loader in bootstrap/app.php nests these under `/v1` and pins
| them to the BAPI host (subdomain mode) or `/api` prefix (path mode).
|
| Real endpoints land in AU-13. The single placeholder route below confirms
| the route group is wired up end-to-end.
*/

Route::get('/_ping', PingController::class)->name('bapi.ping');
