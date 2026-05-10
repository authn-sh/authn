<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\PingController;
use App\Http\Middleware\HandleDashboardInertia;
use App\Http\Middleware\RequireAdminSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard routes (Inertia + React)
|--------------------------------------------------------------------------
|
| Operator UI. Pinned to the Dashboard host (subdomain mode) or `/dashboard`
| prefix (path mode). Auth + Inertia bootstrap handled by the `dashboard`
| middleware group registered in bootstrap/app.php.
|
| URL convention: per-env pages live under `/{project_slug}/{env_slug}/...`
| so the operator can deep-link to a specific env without hidden state.
*/

Route::get('/_ping', PingController::class)
    ->withoutMiddleware([RequireAdminSession::class, HandleDashboardInertia::class])
    ->name('dashboard.ping');

Route::get('/', [DashboardController::class, 'home'])->name('dashboard.home');
Route::get('/create-workspace', [DashboardController::class, 'createWorkspace'])->name('dashboard.create_workspace');
Route::get('/create-project', [DashboardController::class, 'createProject'])->name('dashboard.create_project');
Route::post('/create-project', [DashboardController::class, 'storeProject'])->name('dashboard.store_project');
Route::get('/workspace-settings', [DashboardController::class, 'workspaceSettings'])->name('dashboard.workspace_settings');

Route::prefix('{project_slug}/{env_slug}')->group(function (): void {
    Route::get('/overview', [DashboardController::class, 'overview'])->name('dashboard.overview');
    Route::get('/users', [DashboardController::class, 'users'])->name('dashboard.users');
    Route::get('/sessions', [DashboardController::class, 'sessions'])->name('dashboard.sessions');
    Route::get('/invitations', [DashboardController::class, 'invitations'])->name('dashboard.invitations');
    Route::get('/allowlist', [DashboardController::class, 'allowlist'])->name('dashboard.allowlist');
    Route::get('/blocklist', [DashboardController::class, 'blocklist'])->name('dashboard.blocklist');
    Route::get('/configure/{section?}', [DashboardController::class, 'configure'])->name('dashboard.configure');
    Route::patch('/configure/multi-factor', [DashboardController::class, 'updateMultiFactor'])->name('dashboard.configure.multi_factor.update');
    Route::get('/email-templates', [DashboardController::class, 'emailTemplates'])->name('dashboard.email_templates');

    Route::get('/api-keys', [DashboardController::class, 'apiKeys'])->name('dashboard.api_keys');
    Route::post('/api-keys/{id}/rotate', [DashboardController::class, 'rotateApiKey'])->name('dashboard.api_keys.rotate');

    Route::get('/webhooks', [DashboardController::class, 'webhooks'])->name('dashboard.webhooks');
    Route::post('/webhooks', [DashboardController::class, 'storeWebhook'])->name('dashboard.webhooks.store');

    Route::get('/audit-log', [DashboardController::class, 'auditLog'])->name('dashboard.audit_log');

    // v0.2 panels (AU-13).
    Route::get('/organizations', [DashboardController::class, 'organizations'])->name('dashboard.organizations');
    Route::get('/organizations/{organization_id}', [DashboardController::class, 'organization'])->name('dashboard.organization');
    Route::get('/roles', [DashboardController::class, 'rolesAndPermissions'])->name('dashboard.roles_and_permissions');
});
