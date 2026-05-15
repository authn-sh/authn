<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\ApiKeysController;
use App\Http\Controllers\Dashboard\AppearanceController;
use App\Http\Controllers\Dashboard\AppearancePreviewController;
use App\Http\Controllers\Dashboard\ApplicationsController;
use App\Http\Controllers\Dashboard\AuditLogController;
use App\Http\Controllers\Dashboard\AuthenticationController;
use App\Http\Controllers\Dashboard\AuthorizationController;
use App\Http\Controllers\Dashboard\DashboardHomeController;
use App\Http\Controllers\Dashboard\DomainsController;
use App\Http\Controllers\Dashboard\EnterpriseConnectionsController;
use App\Http\Controllers\Dashboard\IdpAttributesController;
use App\Http\Controllers\Dashboard\JwtTemplatesController;
use App\Http\Controllers\Dashboard\OauthProvidersController;
use App\Http\Controllers\Dashboard\OrganizationsController;
use App\Http\Controllers\Dashboard\OverviewController;
use App\Http\Controllers\Dashboard\PingController;
use App\Http\Controllers\Dashboard\RedirectsController;
use App\Http\Controllers\Dashboard\RestrictionsController;
use App\Http\Controllers\Dashboard\TemplatesController;
use App\Http\Controllers\Dashboard\UsersController;
use App\Http\Controllers\Dashboard\WebhooksController;
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

Route::get('/', [DashboardHomeController::class, 'home'])->name('dashboard.home');
Route::get('/create-workspace', [DashboardHomeController::class, 'createWorkspace'])->name('dashboard.create_workspace');
Route::get('/create-project', [DashboardHomeController::class, 'createProject'])->name('dashboard.create_project');
Route::post('/create-project', [DashboardHomeController::class, 'storeProject'])->name('dashboard.store_project');
Route::get('/workspace-settings', [DashboardHomeController::class, 'workspaceSettings'])->name('dashboard.workspace_settings');

Route::prefix('{project_slug}/{env_slug}')->group(function (): void {
    Route::get('/overview', [OverviewController::class, 'overview'])->name('dashboard.overview');
    Route::get('/users/{tab?}', [UsersController::class, 'usersTab'])
        ->where('tab', 'invitations|sessions')
        ->name('dashboard.users');
    Route::get('/organizations', [OrganizationsController::class, 'organizations'])->name('dashboard.organizations');
    Route::get('/organizations/{organization_id}', [OrganizationsController::class, 'organization'])->name('dashboard.organization');

    Route::prefix('/configure')->group(function (): void {
        Route::get('/authentication/providers/new-oidc', [OauthProvidersController::class, 'newCustomOauthProvider'])->defaults('kind', 'custom_oidc')->name('dashboard.configure.providers.new_oidc');
        Route::get('/authentication/providers/new-oauth2', [OauthProvidersController::class, 'newCustomOauthProvider'])->defaults('kind', 'custom_oauth2')->name('dashboard.configure.providers.new_oauth2');
        Route::get('/authentication/providers/{provider_key}', [OauthProvidersController::class, 'provider'])->name('dashboard.configure.providers.show');
        Route::get('/authentication/{section?}', [AuthenticationController::class, 'authentication'])->name('dashboard.configure.authentication');
        Route::get('/authorization/{tab?}', [AuthorizationController::class, 'authorization'])->name('dashboard.configure.authorization');
        Route::get('/applications', [ApplicationsController::class, 'applications'])->name('dashboard.configure.applications');
        Route::get('/applications/new', [ApplicationsController::class, 'newApplication'])->name('dashboard.configure.applications.new');
        Route::get('/restrictions/{tab?}', [RestrictionsController::class, 'restrictions'])
            ->where('tab', 'allowlist|blocklist')
            ->name('dashboard.configure.restrictions');
        Route::get('/domains', [DomainsController::class, 'domains'])->name('dashboard.configure.domains');
        Route::get('/redirects', [RedirectsController::class, 'redirects'])->name('dashboard.configure.redirects');
        Route::patch('/redirects', [RedirectsController::class, 'updateRedirects'])->name('dashboard.configure.redirects.update');
        Route::get('/idp-attributes', [IdpAttributesController::class, 'idpAttributes'])->name('dashboard.configure.idp_attributes');
        Route::get('/branding/{section?}', [AuthenticationController::class, 'branding'])->name('dashboard.configure.branding');
        Route::get('/templates/{tab?}', [TemplatesController::class, 'templates'])->name('dashboard.configure.templates');
        Route::get('/api-keys', [ApiKeysController::class, 'apiKeys'])->name('dashboard.configure.api_keys');
        Route::post('/api-keys/{id}/rotate', [ApiKeysController::class, 'rotateApiKey'])->name('dashboard.configure.api_keys.rotate');
        Route::get('/webhooks/{tab?}', [WebhooksController::class, 'webhooks'])
            ->where('tab', 'endpoints|deliveries')
            ->name('dashboard.configure.webhooks');
        Route::post('/webhooks', [WebhooksController::class, 'storeWebhook'])->name('dashboard.configure.webhooks.store');
    });

    Route::get('/audit-log', [AuditLogController::class, 'auditLog'])->name('dashboard.audit_log');

    Route::patch('/configure/multi-factor', [AuthenticationController::class, 'updateMultiFactor'])->name('dashboard.configure.multi_factor.update');
    Route::patch('/configure/sign-in-methods', [AuthenticationController::class, 'updateSignInMethods'])->name('dashboard.configure.sign_in_methods.update');
    Route::patch('/configure/sign-up-methods', [AuthenticationController::class, 'updateSignUpMethods'])->name('dashboard.configure.sign_up_methods.update');
    Route::patch('/configure/strategies/passkey', [AuthenticationController::class, 'updatePasskeyEnabled'])->name('dashboard.configure.strategies.passkey.update');
    Route::patch('/configure/appearance', [AppearanceController::class, 'updateAppearance'])->name('dashboard.configure.appearance.update');
    Route::post('/configure/appearance/preview', [AppearancePreviewController::class, 'store'])->name('dashboard.configure.appearance.preview');
    Route::patch('/configure/localization', [AppearanceController::class, 'updateLocalization'])->name('dashboard.configure.localization.update');
    Route::patch('/configure/sms-templates/{slug}', [TemplatesController::class, 'updateSmsTemplate'])->name('dashboard.configure.sms_templates.update');
    Route::post('/configure/oauth-providers', [OauthProvidersController::class, 'storeOauthProvider'])->name('dashboard.configure.oauth_providers.store');
    Route::patch('/configure/oauth-providers/{oauth_provider_id}', [OauthProvidersController::class, 'updateOauthProvider'])->name('dashboard.configure.oauth_providers.update');
    Route::post('/configure/oauth-providers/{oauth_provider_id}/test', [OauthProvidersController::class, 'testOauthProvider'])->name('dashboard.configure.oauth_providers.test');
    Route::post('/configure/enterprise-connections', [EnterpriseConnectionsController::class, 'storeEnterpriseConnection'])->name('dashboard.configure.enterprise_connections.store');
    Route::delete('/configure/enterprise-connections/{enterprise_connection_id}', [EnterpriseConnectionsController::class, 'destroyEnterpriseConnection'])->name('dashboard.configure.enterprise_connections.destroy');
    Route::post('/configure/jwt-templates', [JwtTemplatesController::class, 'storeJwtTemplate'])->name('dashboard.configure.jwt_templates.store');
    Route::patch('/configure/jwt-templates/{jwt_template_id}', [JwtTemplatesController::class, 'updateJwtTemplate'])->name('dashboard.configure.jwt_templates.update');
    Route::delete('/configure/jwt-templates/{jwt_template_id}', [JwtTemplatesController::class, 'destroyJwtTemplate'])->name('dashboard.configure.jwt_templates.destroy');
    Route::post('/configure/oauth-applications', [ApplicationsController::class, 'storeOauthApplication'])->name('dashboard.configure.oauth_applications.store');
    Route::patch('/configure/oauth-applications/{oauth_application_id}', [ApplicationsController::class, 'updateOauthApplication'])->name('dashboard.configure.oauth_applications.update');
    Route::delete('/configure/oauth-applications/{oauth_application_id}', [ApplicationsController::class, 'destroyOauthApplication'])->name('dashboard.configure.oauth_applications.destroy');
    Route::post('/configure/oauth-applications/{oauth_application_id}/rotate-secret', [ApplicationsController::class, 'rotateOauthApplicationSecret'])->name('dashboard.configure.oauth_applications.rotate_secret');
});
