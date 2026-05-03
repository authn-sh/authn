<?php

declare(strict_types=1);

use App\Http\Controllers\Fapi\ClientController;
use App\Http\Controllers\Fapi\EnvironmentController;
use App\Http\Controllers\Fapi\MeController;
use App\Http\Controllers\Fapi\PingController;
use App\Http\Controllers\Fapi\SessionsController;
use App\Http\Controllers\Fapi\SessionTokenController;
use App\Http\Controllers\Fapi\SignInController;
use App\Http\Controllers\Fapi\SignUpController;
use App\Http\Controllers\WellKnown\JwksController;
use App\Http\Controllers\WellKnown\OpenIdConfigurationController;
use App\Http\Middleware\AuthenticateSessionToken;
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

    // Sign-in: POST creates the attempt (and, if needed, the Client). The
    // remaining endpoints require an existing Client cookie.
    Route::post('/client/sign_ins', [SignInController::class, 'store'])->name('fapi.sign_in.store');
    Route::post('/client/sign_ups', [SignUpController::class, 'store'])->name('fapi.sign_up.store');
    Route::middleware(ResolveClientFromCookie::class)->group(function (): void {
        Route::get('/client/sign_ins/{sid}', [SignInController::class, 'show'])->name('fapi.sign_in.show');
        Route::post('/client/sign_ins/{sid}/prepare_first_factor', [SignInController::class, 'prepareFirstFactor'])->name('fapi.sign_in.prepare_first_factor');
        Route::post('/client/sign_ins/{sid}/attempt_first_factor', [SignInController::class, 'attemptFirstFactor'])->name('fapi.sign_in.attempt_first_factor');
        Route::post('/client/sign_ins/{sid}/prepare_second_factor', [SignInController::class, 'prepareSecondFactor'])->name('fapi.sign_in.prepare_second_factor');
        Route::post('/client/sign_ins/{sid}/attempt_second_factor', [SignInController::class, 'attemptSecondFactor'])->name('fapi.sign_in.attempt_second_factor');
        Route::post('/client/sign_ins/{sid}/reset_password', [SignInController::class, 'resetPassword'])->name('fapi.sign_in.reset_password');

        Route::get('/client/sign_ups/{sid}', [SignUpController::class, 'show'])->name('fapi.sign_up.show');
        Route::patch('/client/sign_ups/{sid}', [SignUpController::class, 'patch'])->name('fapi.sign_up.patch');
        Route::post('/client/sign_ups/{sid}/prepare_verification', [SignUpController::class, 'prepareVerification'])->name('fapi.sign_up.prepare_verification');
        Route::post('/client/sign_ups/{sid}/attempt_verification', [SignUpController::class, 'attemptVerification'])->name('fapi.sign_up.attempt_verification');

        Route::get('/client/sessions/{sid}', [SessionsController::class, 'show'])->name('fapi.session.show');
        Route::post('/client/sessions/{sid}/touch', [SessionsController::class, 'touch'])->name('fapi.session.touch');
        Route::post('/client/sessions/{sid}/end', [SessionsController::class, 'end'])->name('fapi.session.end');
        Route::post('/client/sessions/{sid}/remove', [SessionsController::class, 'remove'])->name('fapi.session.remove');

        Route::post('/client/sessions/{sid}/tokens', SessionTokenController::class)->name('fapi.session_token');
        Route::post('/client/sessions/{sid}/tokens/{template}', SessionTokenController::class)->name('fapi.session_token.template');
    });

    // /v1/me — authenticated by __session JWT (Bearer header or cookie).
    Route::middleware(AuthenticateSessionToken::class)->group(function (): void {
        Route::get('/me', [MeController::class, 'show'])->name('fapi.me.show');
        Route::patch('/me', [MeController::class, 'update'])->name('fapi.me.update');
        Route::delete('/me', [MeController::class, 'destroy'])->name('fapi.me.destroy');
        Route::post('/me/delete_self', [MeController::class, 'deleteSelf'])->name('fapi.me.delete_self');

        Route::get('/me/email_addresses', [MeController::class, 'listEmails'])->name('fapi.me.emails.list');
        Route::post('/me/email_addresses', [MeController::class, 'createEmail'])->name('fapi.me.emails.create');
        Route::get('/me/email_addresses/{eid}', [MeController::class, 'showEmail'])->name('fapi.me.emails.show');
        Route::patch('/me/email_addresses/{eid}', [MeController::class, 'updateEmail'])->name('fapi.me.emails.update');
        Route::delete('/me/email_addresses/{eid}', [MeController::class, 'deleteEmail'])->name('fapi.me.emails.destroy');
        Route::post('/me/email_addresses/{eid}/prepare_verification', [MeController::class, 'prepareEmailVerification'])->name('fapi.me.emails.prepare_verification');
        Route::post('/me/email_addresses/{eid}/attempt_verification', [MeController::class, 'attemptEmailVerification'])->name('fapi.me.emails.attempt_verification');

        Route::get('/me/sessions', [MeController::class, 'listSessions'])->name('fapi.me.sessions.list');
        Route::post('/me/change_password', [MeController::class, 'changePassword'])->name('fapi.me.change_password');
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
