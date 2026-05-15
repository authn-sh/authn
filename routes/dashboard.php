<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\AppearancePreviewController;
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
    Route::get('/users/{tab?}', [DashboardController::class, 'usersTab'])
        ->where('tab', 'invitations|sessions')
        ->name('dashboard.users');
    Route::get('/organizations', [DashboardController::class, 'organizations'])->name('dashboard.organizations');
    Route::get('/organizations/{organization_id}', [DashboardController::class, 'organization'])->name('dashboard.organization');

    Route::prefix('/configure')->group(function (): void {
        Route::get('/authentication/providers/new-oidc', [DashboardController::class, 'newCustomOauthProvider'])->defaults('kind', 'custom_oidc')->name('dashboard.configure.providers.new_oidc');
        Route::get('/authentication/providers/new-oauth2', [DashboardController::class, 'newCustomOauthProvider'])->defaults('kind', 'custom_oauth2')->name('dashboard.configure.providers.new_oauth2');
        Route::get('/authentication/providers/{provider_key}', [DashboardController::class, 'provider'])->name('dashboard.configure.providers.show');
        Route::get('/authentication/{section?}', [DashboardController::class, 'authentication'])->name('dashboard.configure.authentication');
        Route::get('/authorization/{tab?}', [DashboardController::class, 'authorization'])->name('dashboard.configure.authorization');
        Route::get('/applications', [DashboardController::class, 'applications'])->name('dashboard.configure.applications');
        Route::get('/applications/new', [DashboardController::class, 'newApplication'])->name('dashboard.configure.applications.new');
        Route::get('/restrictions/{tab?}', [DashboardController::class, 'restrictions'])
            ->where('tab', 'allowlist|blocklist')
            ->name('dashboard.configure.restrictions');
        Route::get('/domains', [DashboardController::class, 'domains'])->name('dashboard.configure.domains');
        Route::get('/redirects', [DashboardController::class, 'redirects'])->name('dashboard.configure.redirects');
        Route::patch('/redirects', [DashboardController::class, 'updateRedirects'])->name('dashboard.configure.redirects.update');
        Route::get('/idp-attributes', [DashboardController::class, 'idpAttributes'])->name('dashboard.configure.idp_attributes');
        Route::get('/branding/{section?}', [DashboardController::class, 'branding'])->name('dashboard.configure.branding');
        Route::get('/templates/{tab?}', [DashboardController::class, 'templates'])->name('dashboard.configure.templates');
        Route::get('/api-keys', [DashboardController::class, 'apiKeys'])->name('dashboard.configure.api_keys');
        Route::post('/api-keys/{id}/rotate', [DashboardController::class, 'rotateApiKey'])->name('dashboard.configure.api_keys.rotate');
        Route::get('/webhooks/{tab?}', [DashboardController::class, 'webhooks'])
            ->where('tab', 'endpoints|deliveries')
            ->name('dashboard.configure.webhooks');
        Route::post('/webhooks', [DashboardController::class, 'storeWebhook'])->name('dashboard.configure.webhooks.store');
    });

    Route::get('/audit-log', [DashboardController::class, 'auditLog'])->name('dashboard.audit_log');

    Route::patch('/configure/multi-factor', [DashboardController::class, 'updateMultiFactor'])->name('dashboard.configure.multi_factor.update');
    Route::patch('/configure/sign-in-methods', [DashboardController::class, 'updateSignInMethods'])->name('dashboard.configure.sign_in_methods.update');
    Route::patch('/configure/sign-up-methods', [DashboardController::class, 'updateSignUpMethods'])->name('dashboard.configure.sign_up_methods.update');
    Route::patch('/configure/strategies/passkey', [DashboardController::class, 'updatePasskeyEnabled'])->name('dashboard.configure.strategies.passkey.update');
    Route::patch('/configure/appearance', [DashboardController::class, 'updateAppearance'])->name('dashboard.configure.appearance.update');
    Route::post('/configure/appearance/preview', [AppearancePreviewController::class, 'store'])->name('dashboard.configure.appearance.preview');
    Route::patch('/configure/localization', [DashboardController::class, 'updateLocalization'])->name('dashboard.configure.localization.update');
    Route::patch('/configure/sms-templates/{slug}', [DashboardController::class, 'updateSmsTemplate'])->name('dashboard.configure.sms_templates.update');
    Route::post('/configure/oauth-providers', [DashboardController::class, 'storeOauthProvider'])->name('dashboard.configure.oauth_providers.store');
    Route::patch('/configure/oauth-providers/{oauth_provider_id}', [DashboardController::class, 'updateOauthProvider'])->name('dashboard.configure.oauth_providers.update');
    Route::post('/configure/oauth-providers/{oauth_provider_id}/test', [DashboardController::class, 'testOauthProvider'])->name('dashboard.configure.oauth_providers.test');
    Route::post('/configure/enterprise-connections', [DashboardController::class, 'storeEnterpriseConnection'])->name('dashboard.configure.enterprise_connections.store');
    Route::delete('/configure/enterprise-connections/{enterprise_connection_id}', [DashboardController::class, 'destroyEnterpriseConnection'])->name('dashboard.configure.enterprise_connections.destroy');
    Route::post('/configure/jwt-templates', [DashboardController::class, 'storeJwtTemplate'])->name('dashboard.configure.jwt_templates.store');
    Route::patch('/configure/jwt-templates/{jwt_template_id}', [DashboardController::class, 'updateJwtTemplate'])->name('dashboard.configure.jwt_templates.update');
    Route::delete('/configure/jwt-templates/{jwt_template_id}', [DashboardController::class, 'destroyJwtTemplate'])->name('dashboard.configure.jwt_templates.destroy');
    Route::post('/configure/oauth-applications', [DashboardController::class, 'storeOauthApplication'])->name('dashboard.configure.oauth_applications.store');
    Route::patch('/configure/oauth-applications/{oauth_application_id}', [DashboardController::class, 'updateOauthApplication'])->name('dashboard.configure.oauth_applications.update');
    Route::delete('/configure/oauth-applications/{oauth_application_id}', [DashboardController::class, 'destroyOauthApplication'])->name('dashboard.configure.oauth_applications.destroy');
    Route::post('/configure/oauth-applications/{oauth_application_id}/rotate-secret', [DashboardController::class, 'rotateOauthApplicationSecret'])->name('dashboard.configure.oauth_applications.rotate_secret');
});
