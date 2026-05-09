<?php

declare(strict_types=1);

use App\Http\Controllers\Bapi\AllowlistIdentifiersController;
use App\Http\Controllers\Bapi\BlocklistIdentifiersController;
use App\Http\Controllers\Bapi\InstanceController;
use App\Http\Controllers\Bapi\InvitationsController;
use App\Http\Controllers\Bapi\OrganizationController;
use App\Http\Controllers\Bapi\OrganizationDomainController;
use App\Http\Controllers\Bapi\OrganizationInvitationController;
use App\Http\Controllers\Bapi\OrganizationMembershipController;
use App\Http\Controllers\Bapi\PermissionController;
use App\Http\Controllers\Bapi\PingController;
use App\Http\Controllers\Bapi\RedirectUrlsController;
use App\Http\Controllers\Bapi\RoleController;
use App\Http\Controllers\Bapi\SessionsController;
use App\Http\Controllers\Bapi\UsersController;
use App\Http\Controllers\Bapi\WebhookDeliveriesController;
use App\Http\Controllers\Bapi\WebhookEndpointsController;
use App\Http\Middleware\RateLimit;
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
| Per-bucket rate limits (PLAN §7.3) are applied via the RateLimit middleware
| with a `bucket,max,window-seconds` parameter.
*/

Route::get('/_ping', PingController::class)->name('bapi.ping');

// Users
Route::get('/users', [UsersController::class, 'index'])->middleware(RateLimit::class.':users.list,300,60')->name('bapi.users.index');
Route::get('/users/count', [UsersController::class, 'count'])->middleware(RateLimit::class.':users.list,300,60')->name('bapi.users.count');
Route::post('/users', [UsersController::class, 'store'])->middleware(RateLimit::class.':users.create,30,60')->name('bapi.users.store');
Route::get('/users/{id}', [UsersController::class, 'show'])->middleware(RateLimit::class.':users.read,300,60')->name('bapi.users.show');
Route::patch('/users/{id}', [UsersController::class, 'update'])->middleware(RateLimit::class.':users.update,60,60')->name('bapi.users.update');
Route::delete('/users/{id}', [UsersController::class, 'destroy'])->middleware(RateLimit::class.':users.destroy,30,60')->name('bapi.users.destroy');
Route::post('/users/{id}/ban', [UsersController::class, 'ban'])->middleware(RateLimit::class.':users.action,60,60')->name('bapi.users.ban');
Route::post('/users/{id}/unban', [UsersController::class, 'unban'])->middleware(RateLimit::class.':users.action,60,60')->name('bapi.users.unban');
Route::post('/users/{id}/lock', [UsersController::class, 'lock'])->middleware(RateLimit::class.':users.action,60,60')->name('bapi.users.lock');
Route::post('/users/{id}/unlock', [UsersController::class, 'unlock'])->middleware(RateLimit::class.':users.action,60,60')->name('bapi.users.unlock');
Route::post('/users/{id}/profile_image', [UsersController::class, 'uploadProfileImage'])->middleware(RateLimit::class.':users.image,30,60')->name('bapi.users.profile_image.upload');
Route::delete('/users/{id}/profile_image', [UsersController::class, 'deleteProfileImage'])->middleware(RateLimit::class.':users.image,30,60')->name('bapi.users.profile_image.delete');
Route::patch('/users/{id}/metadata', [UsersController::class, 'updateMetadata'])->middleware(RateLimit::class.':users.update,60,60')->name('bapi.users.metadata');
Route::post('/users/{id}/verify_password', [UsersController::class, 'verifyPassword'])->middleware(RateLimit::class.':users.verify,60,60')->name('bapi.users.verify_password');

// Sessions
Route::get('/sessions', [SessionsController::class, 'index'])->middleware(RateLimit::class.':sessions.list,300,60')->name('bapi.sessions.index');
Route::get('/sessions/{id}', [SessionsController::class, 'show'])->middleware(RateLimit::class.':sessions.read,300,60')->name('bapi.sessions.show');
Route::post('/sessions/{id}/revoke', [SessionsController::class, 'revoke'])->middleware(RateLimit::class.':sessions.action,60,60')->name('bapi.sessions.revoke');
Route::post('/sessions/{id}/tokens', [SessionsController::class, 'tokens'])->middleware(RateLimit::class.':sessions.tokens,30,60')->name('bapi.sessions.tokens');
Route::post('/sessions/{id}/tokens/{template}', [SessionsController::class, 'tokens'])->middleware(RateLimit::class.':sessions.tokens,30,60')->name('bapi.sessions.tokens.template');

// Invitations
Route::get('/invitations', [InvitationsController::class, 'index'])->middleware(RateLimit::class.':invitations.list,300,60')->name('bapi.invitations.index');
Route::post('/invitations', [InvitationsController::class, 'store'])->middleware(RateLimit::class.':invitations.create,30,60')->name('bapi.invitations.store');
Route::post('/invitations/bulk', [InvitationsController::class, 'bulk'])->middleware(RateLimit::class.':invitations.bulk,5,60')->name('bapi.invitations.bulk');
Route::post('/invitations/{id}/revoke', [InvitationsController::class, 'revoke'])->middleware(RateLimit::class.':invitations.action,60,60')->name('bapi.invitations.revoke');

// Allowlist / Blocklist
Route::get('/allowlist_identifiers', [AllowlistIdentifiersController::class, 'index'])->middleware(RateLimit::class.':lists.list,300,60');
Route::post('/allowlist_identifiers', [AllowlistIdentifiersController::class, 'store'])->middleware(RateLimit::class.':lists.create,60,60');
Route::delete('/allowlist_identifiers/{id}', [AllowlistIdentifiersController::class, 'destroy'])->middleware(RateLimit::class.':lists.destroy,60,60');

Route::get('/blocklist_identifiers', [BlocklistIdentifiersController::class, 'index'])->middleware(RateLimit::class.':lists.list,300,60');
Route::post('/blocklist_identifiers', [BlocklistIdentifiersController::class, 'store'])->middleware(RateLimit::class.':lists.create,60,60');
Route::delete('/blocklist_identifiers/{id}', [BlocklistIdentifiersController::class, 'destroy'])->middleware(RateLimit::class.':lists.destroy,60,60');

// Redirect URLs
Route::get('/redirect_urls', [RedirectUrlsController::class, 'index'])->middleware(RateLimit::class.':redirect_urls.list,300,60');
Route::post('/redirect_urls', [RedirectUrlsController::class, 'store'])->middleware(RateLimit::class.':redirect_urls.create,60,60');
Route::get('/redirect_urls/{id}', [RedirectUrlsController::class, 'show'])->middleware(RateLimit::class.':redirect_urls.read,300,60');
Route::delete('/redirect_urls/{id}', [RedirectUrlsController::class, 'destroy'])->middleware(RateLimit::class.':redirect_urls.destroy,60,60');

// Webhooks
Route::get('/webhooks/endpoints', [WebhookEndpointsController::class, 'index'])->middleware(RateLimit::class.':webhooks.list,300,60');
Route::post('/webhooks/endpoints', [WebhookEndpointsController::class, 'store'])->middleware(RateLimit::class.':webhooks.create,30,60');
Route::get('/webhooks/endpoints/{id}', [WebhookEndpointsController::class, 'show'])->middleware(RateLimit::class.':webhooks.read,300,60');
Route::patch('/webhooks/endpoints/{id}', [WebhookEndpointsController::class, 'update'])->middleware(RateLimit::class.':webhooks.update,60,60');
Route::delete('/webhooks/endpoints/{id}', [WebhookEndpointsController::class, 'destroy'])->middleware(RateLimit::class.':webhooks.destroy,30,60');
Route::post('/webhooks/endpoints/{id}/rotate_secret', [WebhookEndpointsController::class, 'rotateSecret'])->middleware(RateLimit::class.':webhooks.rotate,10,60');

Route::get('/webhooks/deliveries', [WebhookDeliveriesController::class, 'index'])->middleware(RateLimit::class.':webhooks.list,300,60');
Route::get('/webhooks/deliveries/{id}', [WebhookDeliveriesController::class, 'show'])->middleware(RateLimit::class.':webhooks.read,300,60');
Route::post('/webhooks/deliveries/{id}/replay', [WebhookDeliveriesController::class, 'replay'])->middleware(RateLimit::class.':webhooks.replay,30,60');

// Organizations
Route::get('/organizations', [OrganizationController::class, 'index'])->middleware(RateLimit::class.':orgs.list,300,60')->name('bapi.organizations.index');
Route::post('/organizations', [OrganizationController::class, 'store'])->middleware(RateLimit::class.':orgs.create,30,60')->name('bapi.organizations.store');
Route::get('/organizations/{organization_id}', [OrganizationController::class, 'show'])->middleware(RateLimit::class.':orgs.read,300,60')->name('bapi.organizations.show');
Route::patch('/organizations/{organization_id}', [OrganizationController::class, 'update'])->middleware(RateLimit::class.':orgs.update,60,60')->name('bapi.organizations.update');
Route::delete('/organizations/{organization_id}', [OrganizationController::class, 'destroy'])->middleware(RateLimit::class.':orgs.destroy,30,60')->name('bapi.organizations.destroy');

Route::get('/organizations/{organization_id}/memberships', [OrganizationMembershipController::class, 'index'])->middleware(RateLimit::class.':orgs.members.list,300,60')->name('bapi.organizations.memberships.index');
Route::post('/organizations/{organization_id}/memberships', [OrganizationMembershipController::class, 'store'])->middleware(RateLimit::class.':orgs.members.create,60,60')->name('bapi.organizations.memberships.store');
Route::patch('/organizations/{organization_id}/memberships/{user_id}', [OrganizationMembershipController::class, 'update'])->middleware(RateLimit::class.':orgs.members.update,60,60')->name('bapi.organizations.memberships.update');
Route::delete('/organizations/{organization_id}/memberships/{user_id}', [OrganizationMembershipController::class, 'destroy'])->middleware(RateLimit::class.':orgs.members.destroy,60,60')->name('bapi.organizations.memberships.destroy');

Route::get('/organizations/{organization_id}/invitations', [OrganizationInvitationController::class, 'index'])->middleware(RateLimit::class.':orgs.invites.list,300,60')->name('bapi.organizations.invitations.index');
Route::post('/organizations/{organization_id}/invitations', [OrganizationInvitationController::class, 'store'])->middleware(RateLimit::class.':orgs.invites.create,60,60')->name('bapi.organizations.invitations.store');
Route::post('/organizations/{organization_id}/invitations/bulk', [OrganizationInvitationController::class, 'bulkStore'])->middleware(RateLimit::class.':orgs.invites.bulk,5,60')->name('bapi.organizations.invitations.bulk');
Route::post('/organizations/{organization_id}/invitations/{invitation_id}/revoke', [OrganizationInvitationController::class, 'revoke'])->middleware(RateLimit::class.':orgs.invites.action,60,60')->name('bapi.organizations.invitations.revoke');

Route::get('/organizations/{organization_id}/domains', [OrganizationDomainController::class, 'index'])->middleware(RateLimit::class.':orgs.domains.list,300,60')->name('bapi.organizations.domains.index');
Route::post('/organizations/{organization_id}/domains', [OrganizationDomainController::class, 'store'])->middleware(RateLimit::class.':orgs.domains.create,30,60')->name('bapi.organizations.domains.store');
Route::get('/organizations/{organization_id}/domains/{domain_id}', [OrganizationDomainController::class, 'show'])->middleware(RateLimit::class.':orgs.domains.read,300,60')->name('bapi.organizations.domains.show');
Route::patch('/organizations/{organization_id}/domains/{domain_id}', [OrganizationDomainController::class, 'update'])->middleware(RateLimit::class.':orgs.domains.update,60,60')->name('bapi.organizations.domains.update');
Route::delete('/organizations/{organization_id}/domains/{domain_id}', [OrganizationDomainController::class, 'destroy'])->middleware(RateLimit::class.':orgs.domains.destroy,30,60')->name('bapi.organizations.domains.destroy');
Route::post('/organizations/{organization_id}/domains/{domain_id}/verify', [OrganizationDomainController::class, 'verify'])->middleware(RateLimit::class.':orgs.domains.verify,30,60')->name('bapi.organizations.domains.verify');

// Roles + Permissions
Route::get('/roles', [RoleController::class, 'index'])->middleware(RateLimit::class.':roles.list,300,60')->name('bapi.roles.index');
Route::post('/roles', [RoleController::class, 'store'])->middleware(RateLimit::class.':roles.create,30,60')->name('bapi.roles.store');
Route::get('/roles/{role_id}', [RoleController::class, 'show'])->middleware(RateLimit::class.':roles.read,300,60')->name('bapi.roles.show');
Route::patch('/roles/{role_id}', [RoleController::class, 'update'])->middleware(RateLimit::class.':roles.update,60,60')->name('bapi.roles.update');
Route::delete('/roles/{role_id}', [RoleController::class, 'destroy'])->middleware(RateLimit::class.':roles.destroy,30,60')->name('bapi.roles.destroy');
Route::put('/roles/{role_id}/permissions', [RoleController::class, 'setPermissions'])->middleware(RateLimit::class.':roles.permissions,60,60')->name('bapi.roles.permissions.set');

Route::get('/permissions', [PermissionController::class, 'index'])->middleware(RateLimit::class.':permissions.list,300,60')->name('bapi.permissions.index');

// Instance settings
Route::get('/instance', [InstanceController::class, 'show'])->middleware(RateLimit::class.':instance.read,300,60');
Route::patch('/instance', [InstanceController::class, 'update'])->middleware(RateLimit::class.':instance.update,30,60');
Route::patch('/instance/restrictions', [InstanceController::class, 'updateRestrictions'])->middleware(RateLimit::class.':instance.update,30,60');
Route::patch('/instance/organization_settings', [InstanceController::class, 'updateOrganizationSettings'])->middleware(RateLimit::class.':instance.update,30,60');
