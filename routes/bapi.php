<?php

declare(strict_types=1);

use App\Http\Controllers\Bapi\AllowlistIdentifiersController;
use App\Http\Controllers\Bapi\BlocklistIdentifiersController;
use App\Http\Controllers\Bapi\EnterpriseAccountController;
use App\Http\Controllers\Bapi\EnterpriseConnectionController;
use App\Http\Controllers\Bapi\ExternalAccountController;
use App\Http\Controllers\Bapi\InstanceAppearanceController;
use App\Http\Controllers\Bapi\InstanceController;
use App\Http\Controllers\Bapi\InstanceLocalizationController;
use App\Http\Controllers\Bapi\InvitationsController;
use App\Http\Controllers\Bapi\JwtTemplateController;
use App\Http\Controllers\Bapi\OauthProviderController;
use App\Http\Controllers\Bapi\OrganizationController;
use App\Http\Controllers\Bapi\OrganizationDomainChallengeController;
use App\Http\Controllers\Bapi\OrganizationDomainController;
use App\Http\Controllers\Bapi\OrganizationInvitationController;
use App\Http\Controllers\Bapi\OrganizationMembershipController;
use App\Http\Controllers\Bapi\OrganizationScimController;
use App\Http\Controllers\Bapi\PasskeyController;
use App\Http\Controllers\Bapi\PermissionController;
use App\Http\Controllers\Bapi\PhoneNumberController;
use App\Http\Controllers\Bapi\PingController;
use App\Http\Controllers\Bapi\RedirectUrlsController;
use App\Http\Controllers\Bapi\RoleController;
use App\Http\Controllers\Bapi\SessionsController;
use App\Http\Controllers\Bapi\SmsTemplatesController;
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
Route::post('/users/{id}/profile-image', [UsersController::class, 'uploadProfileImage'])->middleware(RateLimit::class.':users.image,30,60')->name('bapi.users.profile_image.upload');
Route::delete('/users/{id}/profile-image', [UsersController::class, 'deleteProfileImage'])->middleware(RateLimit::class.':users.image,30,60')->name('bapi.users.profile_image.delete');
Route::patch('/users/{id}/metadata', [UsersController::class, 'updateMetadata'])->middleware(RateLimit::class.':users.update,60,60')->name('bapi.users.metadata');
Route::post('/users/{id}/verify-password', [UsersController::class, 'verifyPassword'])->middleware(RateLimit::class.':users.verify,60,60')->name('bapi.users.verify_password');
Route::post('/users/{id}/verify-totp', [UsersController::class, 'verifyTotp'])->middleware(RateLimit::class.':users.verify,60,60')->name('bapi.users.verify_totp');
Route::delete('/users/{id}/mfa', [UsersController::class, 'deleteMfa'])->middleware(RateLimit::class.':users.action,60,60')->name('bapi.users.mfa.destroy');

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
Route::get('/allowlist-identifiers', [AllowlistIdentifiersController::class, 'index'])->middleware(RateLimit::class.':lists.list,300,60');
Route::post('/allowlist-identifiers', [AllowlistIdentifiersController::class, 'store'])->middleware(RateLimit::class.':lists.create,60,60');
Route::delete('/allowlist-identifiers/{id}', [AllowlistIdentifiersController::class, 'destroy'])->middleware(RateLimit::class.':lists.destroy,60,60');

Route::get('/blocklist-identifiers', [BlocklistIdentifiersController::class, 'index'])->middleware(RateLimit::class.':lists.list,300,60');
Route::post('/blocklist-identifiers', [BlocklistIdentifiersController::class, 'store'])->middleware(RateLimit::class.':lists.create,60,60');
Route::delete('/blocklist-identifiers/{id}', [BlocklistIdentifiersController::class, 'destroy'])->middleware(RateLimit::class.':lists.destroy,60,60');

// Redirect URLs
Route::get('/redirect-urls', [RedirectUrlsController::class, 'index'])->middleware(RateLimit::class.':redirect_urls.list,300,60');
Route::post('/redirect-urls', [RedirectUrlsController::class, 'store'])->middleware(RateLimit::class.':redirect_urls.create,60,60');
Route::get('/redirect-urls/{id}', [RedirectUrlsController::class, 'show'])->middleware(RateLimit::class.':redirect_urls.read,300,60');
Route::delete('/redirect-urls/{id}', [RedirectUrlsController::class, 'destroy'])->middleware(RateLimit::class.':redirect_urls.destroy,60,60');

// JWT templates (OA-1).
Route::get('/jwt-templates', [JwtTemplateController::class, 'index'])->middleware(RateLimit::class.':jwt_templates.list,300,60')->name('bapi.jwt_templates.index');
Route::post('/jwt-templates', [JwtTemplateController::class, 'store'])->middleware(RateLimit::class.':jwt_templates.create,30,60')->name('bapi.jwt_templates.store');
Route::get('/jwt-templates/{jwt_template_id}', [JwtTemplateController::class, 'show'])->middleware(RateLimit::class.':jwt_templates.read,300,60')->name('bapi.jwt_templates.show');
Route::patch('/jwt-templates/{jwt_template_id}', [JwtTemplateController::class, 'update'])->middleware(RateLimit::class.':jwt_templates.update,60,60')->name('bapi.jwt_templates.update');
Route::delete('/jwt-templates/{jwt_template_id}', [JwtTemplateController::class, 'destroy'])->middleware(RateLimit::class.':jwt_templates.destroy,30,60')->name('bapi.jwt_templates.destroy');

// Webhooks
Route::get('/webhooks/endpoints', [WebhookEndpointsController::class, 'index'])->middleware(RateLimit::class.':webhooks.list,300,60');
Route::post('/webhooks/endpoints', [WebhookEndpointsController::class, 'store'])->middleware(RateLimit::class.':webhooks.create,30,60');
Route::get('/webhooks/endpoints/{id}', [WebhookEndpointsController::class, 'show'])->middleware(RateLimit::class.':webhooks.read,300,60');
Route::patch('/webhooks/endpoints/{id}', [WebhookEndpointsController::class, 'update'])->middleware(RateLimit::class.':webhooks.update,60,60');
Route::delete('/webhooks/endpoints/{id}', [WebhookEndpointsController::class, 'destroy'])->middleware(RateLimit::class.':webhooks.destroy,30,60');
Route::post('/webhooks/endpoints/{id}/rotate-secret', [WebhookEndpointsController::class, 'rotateSecret'])->middleware(RateLimit::class.':webhooks.rotate,10,60');

Route::get('/webhooks/deliveries', [WebhookDeliveriesController::class, 'index'])->middleware(RateLimit::class.':webhooks.list,300,60');
Route::get('/webhooks/deliveries/{id}', [WebhookDeliveriesController::class, 'show'])->middleware(RateLimit::class.':webhooks.read,300,60');
Route::post('/webhooks/deliveries/{id}/replay', [WebhookDeliveriesController::class, 'replay'])->middleware(RateLimit::class.':webhooks.replay,30,60');

// SMS templates
Route::get('/sms-templates', [SmsTemplatesController::class, 'index'])->middleware(RateLimit::class.':sms_templates.list,300,60')->name('bapi.sms_templates.index');
Route::get('/sms-templates/{slug}', [SmsTemplatesController::class, 'show'])->middleware(RateLimit::class.':sms_templates.read,300,60')->name('bapi.sms_templates.show');
Route::patch('/sms-templates/{slug}', [SmsTemplatesController::class, 'update'])->middleware(RateLimit::class.':sms_templates.update,60,60')->name('bapi.sms_templates.update');
Route::post('/sms-templates/{slug}/revert', [SmsTemplatesController::class, 'revert'])->middleware(RateLimit::class.':sms_templates.update,30,60')->name('bapi.sms_templates.revert');

// OAuth providers
Route::get('/oauth-providers', [OauthProviderController::class, 'index'])->middleware(RateLimit::class.':oauth_providers.list,300,60')->name('bapi.oauth_providers.index');
Route::post('/oauth-providers', [OauthProviderController::class, 'store'])->middleware(RateLimit::class.':oauth_providers.create,30,60')->name('bapi.oauth_providers.store');
Route::get('/oauth-providers/{oauth_provider_id}', [OauthProviderController::class, 'show'])->middleware(RateLimit::class.':oauth_providers.read,300,60')->name('bapi.oauth_providers.show');
Route::patch('/oauth-providers/{oauth_provider_id}', [OauthProviderController::class, 'update'])->middleware(RateLimit::class.':oauth_providers.update,60,60')->name('bapi.oauth_providers.update');
Route::delete('/oauth-providers/{oauth_provider_id}', [OauthProviderController::class, 'destroy'])->middleware(RateLimit::class.':oauth_providers.destroy,30,60')->name('bapi.oauth_providers.destroy');
Route::post('/oauth-providers/{oauth_provider_id}/test', [OauthProviderController::class, 'test'])->middleware(RateLimit::class.':oauth_providers.test,30,60')->name('bapi.oauth_providers.test');

// Enterprise connections (SAML + OIDC)
Route::get('/enterprise-connections', [EnterpriseConnectionController::class, 'index'])->middleware(RateLimit::class.':enterprise_connections.list,300,60')->name('bapi.enterprise_connections.index');
Route::post('/enterprise-connections', [EnterpriseConnectionController::class, 'store'])->middleware(RateLimit::class.':enterprise_connections.create,30,60')->name('bapi.enterprise_connections.store');
Route::get('/enterprise-connections/{enterprise_connection_id}', [EnterpriseConnectionController::class, 'show'])->middleware(RateLimit::class.':enterprise_connections.read,300,60')->name('bapi.enterprise_connections.show');
Route::patch('/enterprise-connections/{enterprise_connection_id}', [EnterpriseConnectionController::class, 'update'])->middleware(RateLimit::class.':enterprise_connections.update,60,60')->name('bapi.enterprise_connections.update');
Route::delete('/enterprise-connections/{enterprise_connection_id}', [EnterpriseConnectionController::class, 'destroy'])->middleware(RateLimit::class.':enterprise_connections.destroy,30,60')->name('bapi.enterprise_connections.destroy');
Route::post('/enterprise-connections/{enterprise_connection_id}/test', [EnterpriseConnectionController::class, 'test'])->middleware(RateLimit::class.':enterprise_connections.test,30,60')->name('bapi.enterprise_connections.test');

// Enterprise accounts (admin read + unlink). Provisioning happens via the SSO callback, not BAPI.
Route::get('/enterprise-accounts', [EnterpriseAccountController::class, 'index'])->middleware(RateLimit::class.':enterprise_accounts.list,300,60')->name('bapi.enterprise_accounts.index');
Route::get('/enterprise-accounts/{enterprise_account_id}', [EnterpriseAccountController::class, 'show'])->middleware(RateLimit::class.':enterprise_accounts.read,300,60')->name('bapi.enterprise_accounts.show');
Route::delete('/enterprise-accounts/{enterprise_account_id}', [EnterpriseAccountController::class, 'destroy'])->middleware(RateLimit::class.':enterprise_accounts.destroy,30,60')->name('bapi.enterprise_accounts.destroy');

// Passkeys (admin) — sdk-php SP-1 wraps this.
Route::get('/passkeys', [PasskeyController::class, 'index'])->middleware(RateLimit::class.':passkeys.list,300,60')->name('bapi.passkeys.index');
Route::get('/passkeys/{passkey_id}', [PasskeyController::class, 'show'])->middleware(RateLimit::class.':passkeys.read,300,60')->name('bapi.passkeys.show');
Route::patch('/passkeys/{passkey_id}', [PasskeyController::class, 'update'])->middleware(RateLimit::class.':passkeys.update,60,60')->name('bapi.passkeys.update');
Route::delete('/passkeys/{passkey_id}', [PasskeyController::class, 'destroy'])->middleware(RateLimit::class.':passkeys.destroy,30,60')->name('bapi.passkeys.destroy');

// Phone numbers (admin)
Route::get('/phone-numbers', [PhoneNumberController::class, 'index'])->middleware(RateLimit::class.':phone_numbers.list,300,60')->name('bapi.phone_numbers.index');
Route::post('/phone-numbers', [PhoneNumberController::class, 'store'])->middleware(RateLimit::class.':phone_numbers.create,60,60')->name('bapi.phone_numbers.store');
Route::get('/phone-numbers/{phone_number_id}', [PhoneNumberController::class, 'show'])->middleware(RateLimit::class.':phone_numbers.read,300,60')->name('bapi.phone_numbers.show');
Route::patch('/phone-numbers/{phone_number_id}', [PhoneNumberController::class, 'update'])->middleware(RateLimit::class.':phone_numbers.update,60,60')->name('bapi.phone_numbers.update');
Route::delete('/phone-numbers/{phone_number_id}', [PhoneNumberController::class, 'destroy'])->middleware(RateLimit::class.':phone_numbers.destroy,30,60')->name('bapi.phone_numbers.destroy');

// External accounts (admin)
Route::get('/external-accounts', [ExternalAccountController::class, 'index'])->middleware(RateLimit::class.':external_accounts.list,300,60')->name('bapi.external_accounts.index');
Route::get('/external-accounts/{external_account_id}', [ExternalAccountController::class, 'show'])->middleware(RateLimit::class.':external_accounts.read,300,60')->name('bapi.external_accounts.read');
Route::delete('/external-accounts/{external_account_id}', [ExternalAccountController::class, 'destroy'])->middleware(RateLimit::class.':external_accounts.destroy,30,60')->name('bapi.external_accounts.destroy');

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

Route::post('/organizations/{organization_id}/domains/{domain_id}/challenges', [OrganizationDomainChallengeController::class, 'store'])->middleware(RateLimit::class.':orgs.domains.verify,30,60')->name('bapi.organizations.domains.challenges.store');
Route::post('/organizations/{organization_id}/domains/{domain_id}/challenges/{cid}/answer', [OrganizationDomainChallengeController::class, 'answer'])->middleware(RateLimit::class.':orgs.domains.verify,30,60')->name('bapi.organizations.domains.challenges.answer');
Route::get('/organizations/{organization_id}/domains/{domain_id}/challenges/{cid}', [OrganizationDomainChallengeController::class, 'show'])->middleware(RateLimit::class.':orgs.domains.read,300,60')->name('bapi.organizations.domains.challenges.show');

// Per-org SCIM admin (BAPI mirror of FAPI /v1/organizations/{org_id}/scim/...).
Route::get('/organizations/{organization_id}/scim/tokens', [OrganizationScimController::class, 'listTokens'])->middleware(RateLimit::class.':orgs.scim.list,300,60')->name('bapi.organizations.scim.tokens.index');
Route::post('/organizations/{organization_id}/scim/tokens', [OrganizationScimController::class, 'issueToken'])->middleware(RateLimit::class.':orgs.scim.issue,30,60')->name('bapi.organizations.scim.tokens.store');
Route::post('/organizations/{organization_id}/scim/tokens/{scim_token_id}/revoke', [OrganizationScimController::class, 'revokeToken'])->middleware(RateLimit::class.':orgs.scim.revoke,30,60')->name('bapi.organizations.scim.tokens.revoke');
Route::get('/organizations/{organization_id}/scim/attribute-mappings', [OrganizationScimController::class, 'showAttributeMappings'])->middleware(RateLimit::class.':orgs.scim.read,300,60')->name('bapi.organizations.scim.attribute_mappings.show');
Route::put('/organizations/{organization_id}/scim/attribute-mappings', [OrganizationScimController::class, 'replaceAttributeMappings'])->middleware(RateLimit::class.':orgs.scim.update,30,60')->name('bapi.organizations.scim.attribute_mappings.replace');
Route::get('/organizations/{organization_id}/scim/endpoint', [OrganizationScimController::class, 'showEndpoint'])->middleware(RateLimit::class.':orgs.scim.read,300,60')->name('bapi.organizations.scim.endpoint.show');

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
Route::patch('/instance/organization-settings', [InstanceController::class, 'updateOrganizationSettings'])->middleware(RateLimit::class.':instance.update,30,60');

Route::get('/instance/appearance', [InstanceAppearanceController::class, 'show'])->middleware(RateLimit::class.':instance.read,300,60')->name('bapi.instance.appearance.show');
Route::put('/instance/appearance', [InstanceAppearanceController::class, 'replace'])->middleware(RateLimit::class.':instance.update,30,60')->name('bapi.instance.appearance.replace');
Route::patch('/instance/appearance', [InstanceAppearanceController::class, 'patch'])->middleware(RateLimit::class.':instance.update,30,60')->name('bapi.instance.appearance.patch');

Route::get('/instance/localization', [InstanceLocalizationController::class, 'show'])->middleware(RateLimit::class.':instance.read,300,60')->name('bapi.instance.localization.show');
Route::put('/instance/localization', [InstanceLocalizationController::class, 'replace'])->middleware(RateLimit::class.':instance.update,30,60')->name('bapi.instance.localization.replace');
Route::patch('/instance/localization', [InstanceLocalizationController::class, 'patch'])->middleware(RateLimit::class.':instance.update,30,60')->name('bapi.instance.localization.patch');
