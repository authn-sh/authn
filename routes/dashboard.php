<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\PingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard routes
|--------------------------------------------------------------------------
|
| Operator UI. Pinned to the Dashboard host (subdomain mode) or `/dashboard`
| prefix (path mode). Auth lands in AU-17 (RequireAdminSession).
*/

Route::get('/_ping', PingController::class)->name('dashboard.ping');
