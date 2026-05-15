<?php

declare(strict_types=1);

use App\Http\Controllers\AccountPortal\AccountPortalController;
use App\Http\Controllers\AccountPortal\AppearancePreviewRenderController;
use App\Http\Controllers\Fapi\ChallengeController;
use App\Http\Controllers\Fapi\ClientController;
use App\Http\Controllers\Fapi\EnterpriseSsoCallbackController;
use App\Http\Controllers\Fapi\EnvironmentController;
use App\Http\Controllers\Fapi\LocalizationController;
use App\Http\Controllers\Fapi\MagicLinkController;
use App\Http\Controllers\Fapi\MeAuthorizedAppsController;
use App\Http\Controllers\Fapi\MeBackupCodesController;
use App\Http\Controllers\Fapi\MeController;
use App\Http\Controllers\Fapi\MeExternalAccountController;
use App\Http\Controllers\Fapi\MeOrganizationController;
use App\Http\Controllers\Fapi\MePasskeysController;
use App\Http\Controllers\Fapi\MePhoneNumberController;
use App\Http\Controllers\Fapi\MeTotpController;
use App\Http\Controllers\Fapi\OauthAuthorizeController;
use App\Http\Controllers\Fapi\OauthCallbackController;
use App\Http\Controllers\Fapi\OauthConsentController;
use App\Http\Controllers\Fapi\OauthTokenController;
use App\Http\Controllers\Fapi\OauthUserinfoController;
use App\Http\Controllers\Fapi\OrganizationController as FapiOrganizationController;
use App\Http\Controllers\Fapi\OrganizationDomainController as FapiOrganizationDomainController;
use App\Http\Controllers\Fapi\OrganizationEnterpriseConnectionController;
use App\Http\Controllers\Fapi\OrganizationInvitationController as FapiOrganizationInvitationController;
use App\Http\Controllers\Fapi\OrganizationMembershipController as FapiOrganizationMembershipController;
use App\Http\Controllers\Fapi\OrganizationMembershipRequestController as FapiOrganizationMembershipRequestController;
use App\Http\Controllers\Fapi\OrganizationScimController;
use App\Http\Controllers\Fapi\PingController;
use App\Http\Controllers\Fapi\SessionsController;
use App\Http\Controllers\Fapi\SessionTokenController;
use App\Http\Controllers\Fapi\SignInController;
use App\Http\Controllers\Fapi\SignUpController;
use App\Http\Controllers\Scim\GroupsController as ScimGroupsController;
use App\Http\Controllers\Scim\UsersController as ScimUsersController;
use App\Http\Controllers\WellKnown\JwksController;
use App\Http\Controllers\WellKnown\OpenIdConfigurationController;
use App\Http\Middleware\AuthenticateSessionToken;
use App\Http\Middleware\EnforceFapiOrigin;
use App\Http\Middleware\EnsureOrgPermission;
use App\Http\Middleware\HandleAccountPortalInertia;
use App\Http\Middleware\ResolveClientFromCookie;
use App\Http\Middleware\ScimAuth;
use App\Models\Environment;
use App\Support\Url;
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

    // OAuth provider mode (AU-6). Browser-initiated top-level navigation; no
    // Origin header / Client cookie required up front. Cache-Control: no-store
    // is set on every response per RFC 6749 §5.1.
    Route::get('/oauth/authorize', OauthAuthorizeController::class)
        ->name('fapi.oauth.authorize');

    // AU-7: token + introspection. Server-to-server (no Origin); confidential
    // clients authenticate via Basic auth / post creds, public via PKCE.
    Route::post('/oauth/token', [OauthTokenController::class, 'token'])->name('fapi.oauth.token');
    Route::post('/oauth/token_info', [OauthTokenController::class, 'tokenInfo'])->name('fapi.oauth.token_info');

    // AU-8: OIDC userinfo. Bearer-authenticated by the AU-7 access_token.
    Route::get('/oauth/userinfo', OauthUserinfoController::class)->name('fapi.oauth.userinfo');
});

// SCIM 2.0 server (RFC 7644). Bearer-authenticated; the SCIM client is the
// IdP, not the SDK, so Origin enforcement is skipped and the routes sit
// outside the /v1 prefix to match the SCIM convention IdPs expect.
Route::withoutMiddleware([EnforceFapiOrigin::class])->prefix('scim/v2')->middleware(ScimAuth::class)->group(function (): void {
    Route::get('/Users', [ScimUsersController::class, 'index'])->name('scim.users.index');
    Route::post('/Users', [ScimUsersController::class, 'store'])->name('scim.users.store');
    Route::get('/Users/{id}', [ScimUsersController::class, 'show'])->name('scim.users.show');
    Route::put('/Users/{id}', [ScimUsersController::class, 'update'])->name('scim.users.update');
    Route::patch('/Users/{id}', [ScimUsersController::class, 'patch'])->name('scim.users.patch');
    Route::delete('/Users/{id}', [ScimUsersController::class, 'destroy'])->name('scim.users.destroy');

    Route::get('/Groups', [ScimGroupsController::class, 'index'])->name('scim.groups.index');
    Route::get('/Groups/{id}', [ScimGroupsController::class, 'show'])->name('scim.groups.show');
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
        Route::get('/localization/{locale}', [LocalizationController::class, 'show'])->name('fapi.localization.show');
        Route::get('/client', [ClientController::class, 'show'])->name('fapi.client.show');
        Route::put('/client', [ClientController::class, 'store'])->name('fapi.client.store');
        Route::delete('/client', [ClientController::class, 'destroy'])->name('fapi.client.destroy');
        Route::match(['get', 'post'], '/client/handshake', [ClientController::class, 'handshake'])->name('fapi.client.handshake');

        // Magic-link click handler (AU-10). Top-level browser navigation
        // from the email — no Origin header, no Client cookie required.
        Route::get('/client/magic-link/redeem', [MagicLinkController::class, 'redeem'])->name('fapi.magic_link.redeem');

        // OAuth callback — IdP top-level redirect back from the authorize
        // dance. No Origin / Client cookie required (redirect lands on a
        // fresh navigation context); state token in the query string is
        // the integrity check.
        Route::get('/oauth-callback/{provider_key}', OauthCallbackController::class)->name('fapi.oauth_callback');

        // Enterprise SSO callbacks (AU-7). OIDC = GET redirect; SAML = POST ACS.
        Route::get('/enterprise-sso-callback', [EnterpriseSsoCallbackController::class, 'oidcCallback'])
            ->name('fapi.enterprise_sso.oidc_callback');
        Route::get('/enterprise-sso-callback/{connection_id}', [EnterpriseSsoCallbackController::class, 'oidcCallback'])
            ->name('fapi.enterprise_sso.oidc_callback_with_connection');
        Route::post('/saml/{connection_id}/acs', [EnterpriseSsoCallbackController::class, 'samlAcs'])
            ->name('fapi.enterprise_sso.saml_acs');
    });

    // Sign-in: POST creates the attempt (and, if needed, the Client). The
    // remaining endpoints require an existing Client cookie.
    Route::post('/client/sign-ins', [SignInController::class, 'store'])->name('fapi.sign_in.store');
    Route::post('/client/sign-ups', [SignUpController::class, 'store'])->name('fapi.sign_up.store');
    Route::middleware(ResolveClientFromCookie::class)->group(function (): void {
        Route::get('/client/sign-ins/{sid}', [SignInController::class, 'show'])->name('fapi.sign_in.show');
        Route::patch('/client/sign-ins/{sid}', [SignInController::class, 'patch'])->name('fapi.sign_in.patch');

        Route::post('/client/sign-ins/{sid}/challenges', [ChallengeController::class, 'storeForSignIn'])->name('fapi.sign_in.challenges.store');
        Route::post('/client/sign-ins/{sid}/challenges/{cid}/answer', [ChallengeController::class, 'answerForSignIn'])->name('fapi.sign_in.challenges.answer');
        Route::get('/client/sign-ins/{sid}/challenges/{cid}', [ChallengeController::class, 'showForSignIn'])->name('fapi.sign_in.challenges.show');

        Route::get('/client/sign-ups/{sid}', [SignUpController::class, 'show'])->name('fapi.sign_up.show');
        Route::patch('/client/sign-ups/{sid}', [SignUpController::class, 'patch'])->name('fapi.sign_up.patch');

        Route::post('/client/sign-ups/{sid}/challenges', [ChallengeController::class, 'storeForSignUp'])->name('fapi.sign_up.challenges.store');
        Route::post('/client/sign-ups/{sid}/challenges/{cid}/answer', [ChallengeController::class, 'answerForSignUp'])->name('fapi.sign_up.challenges.answer');
        Route::get('/client/sign-ups/{sid}/challenges/{cid}', [ChallengeController::class, 'showForSignUp'])->name('fapi.sign_up.challenges.show');

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
        Route::post('/me/delete-self', [MeController::class, 'deleteSelf'])->name('fapi.me.delete_self');

        Route::get('/me/email-addresses', [MeController::class, 'listEmails'])->name('fapi.me.emails.list');
        Route::post('/me/email-addresses', [MeController::class, 'createEmail'])->name('fapi.me.emails.create');
        Route::get('/me/email-addresses/{email_address_id}', [MeController::class, 'showEmail'])->name('fapi.me.emails.show');
        Route::patch('/me/email-addresses/{email_address_id}', [MeController::class, 'updateEmail'])->name('fapi.me.emails.update');
        Route::delete('/me/email-addresses/{email_address_id}', [MeController::class, 'deleteEmail'])->name('fapi.me.emails.destroy');

        Route::get('/me/phone-numbers', [MePhoneNumberController::class, 'index'])->name('fapi.me.phones.list');
        Route::post('/me/phone-numbers', [MePhoneNumberController::class, 'store'])->name('fapi.me.phones.create');
        Route::get('/me/phone-numbers/{phone_number_id}', [MePhoneNumberController::class, 'show'])->name('fapi.me.phones.show');
        Route::patch('/me/phone-numbers/{phone_number_id}', [MePhoneNumberController::class, 'update'])->name('fapi.me.phones.update');
        Route::delete('/me/phone-numbers/{phone_number_id}', [MePhoneNumberController::class, 'destroy'])->name('fapi.me.phones.destroy');

        Route::get('/me/external-accounts', [MeExternalAccountController::class, 'index'])->name('fapi.me.external_accounts.list');
        Route::get('/me/external-accounts/{external_account_id}', [MeExternalAccountController::class, 'show'])->name('fapi.me.external_accounts.show');
        Route::delete('/me/external-accounts/{external_account_id}', [MeExternalAccountController::class, 'destroy'])->name('fapi.me.external_accounts.destroy');

        Route::post('/me/email-addresses/{email_address_id}/challenges', [ChallengeController::class, 'storeForEmailAddress'])->name('fapi.me.emails.challenges.store');
        Route::post('/me/email-addresses/{email_address_id}/challenges/{cid}/answer', [ChallengeController::class, 'answerForEmailAddress'])->name('fapi.me.emails.challenges.answer');
        Route::get('/me/email-addresses/{email_address_id}/challenges/{cid}', [ChallengeController::class, 'showForEmailAddress'])->name('fapi.me.emails.challenges.show');

        Route::get('/me/sessions', [MeController::class, 'listSessions'])->name('fapi.me.sessions.list');
        Route::post('/me/change-password', [MeController::class, 'changePassword'])->name('fapi.me.change_password');
        Route::delete('/me/password', [MeController::class, 'removeMyPassword'])->name('fapi.me.password.delete');

        Route::post('/me/profile-image', [MeController::class, 'uploadMyProfileImage'])->name('fapi.me.profile_image.upload');
        Route::delete('/me/profile-image', [MeController::class, 'deleteMyProfileImage'])->name('fapi.me.profile_image.delete');

        Route::post('/me/totp', [MeTotpController::class, 'start'])->name('fapi.me.totp.start');
        Route::post('/me/totp/verify', [MeTotpController::class, 'verify'])->name('fapi.me.totp.verify');
        Route::get('/me/totp', [MeTotpController::class, 'show'])->name('fapi.me.totp.show');
        Route::delete('/me/totp', [MeTotpController::class, 'destroy'])->name('fapi.me.totp.destroy');

        Route::post('/me/backup-codes', [MeBackupCodesController::class, 'regenerate'])->name('fapi.me.backup_codes.regenerate');
        Route::get('/me/backup-codes', [MeBackupCodesController::class, 'show'])->name('fapi.me.backup_codes.show');
        Route::delete('/me/backup-codes', [MeBackupCodesController::class, 'destroy'])->name('fapi.me.backup_codes.destroy');

        Route::get('/me/passkeys', [MePasskeysController::class, 'index'])->name('fapi.me.passkeys.list');
        Route::post('/me/passkeys/begin-registration', [MePasskeysController::class, 'beginRegistration'])->name('fapi.me.passkeys.begin');
        Route::post('/me/passkeys/complete-registration/{challenge_id}', [MePasskeysController::class, 'completeRegistration'])->name('fapi.me.passkeys.complete');
        Route::patch('/me/passkeys/{passkey_id}', [MePasskeysController::class, 'update'])->name('fapi.me.passkeys.update');
        Route::delete('/me/passkeys/{passkey_id}', [MePasskeysController::class, 'destroy'])->name('fapi.me.passkeys.destroy');

        // Authorized apps — the active user's view of their OAuth consents (AU-10).
        Route::get('/me/authorized-apps', [MeAuthorizedAppsController::class, 'index'])->name('fapi.me.authorized_apps.list');
        Route::delete('/me/authorized-apps/{authorization_grant_id}', [MeAuthorizedAppsController::class, 'destroy'])->name('fapi.me.authorized_apps.destroy');

        // Organizations — user-scoped CRUD (PLAN §4.4 / OA-3 / AU-6).
        Route::post('/organizations', [FapiOrganizationController::class, 'store'])->name('fapi.organizations.store');
        Route::post('/organizations/{organization_id}/leave', [FapiOrganizationController::class, 'leave'])->name('fapi.organizations.leave');
        Route::get('/organizations/{organization_id}', [FapiOrganizationController::class, 'show'])
            ->middleware(EnsureOrgPermission::class.':org:sys_profile:read')
            ->name('fapi.organizations.show');
        Route::patch('/organizations/{organization_id}', [FapiOrganizationController::class, 'update'])
            ->middleware(EnsureOrgPermission::class.':org:sys_profile:manage')
            ->name('fapi.organizations.update');
        Route::delete('/organizations/{organization_id}', [FapiOrganizationController::class, 'destroy'])
            ->middleware(EnsureOrgPermission::class.':org:sys_profile:delete')
            ->name('fapi.organizations.destroy');

        Route::get('/organizations/{organization_id}/memberships', [FapiOrganizationMembershipController::class, 'index'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:read')
            ->name('fapi.organizations.memberships.index');
        Route::post('/organizations/{organization_id}/memberships', [FapiOrganizationMembershipController::class, 'store'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:manage')
            ->name('fapi.organizations.memberships.store');
        Route::patch('/organizations/{organization_id}/memberships/{user_id}', [FapiOrganizationMembershipController::class, 'update'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:manage')
            ->name('fapi.organizations.memberships.update');
        Route::delete('/organizations/{organization_id}/memberships/{user_id}', [FapiOrganizationMembershipController::class, 'destroy'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:manage')
            ->name('fapi.organizations.memberships.destroy');

        // Per-org admin invitation surface (AU-7).
        Route::get('/organizations/{organization_id}/invitations', [FapiOrganizationInvitationController::class, 'index'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:read')
            ->name('fapi.organizations.invitations.index');
        Route::post('/organizations/{organization_id}/invitations', [FapiOrganizationInvitationController::class, 'store'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:manage')
            ->name('fapi.organizations.invitations.store');
        Route::post('/organizations/{organization_id}/invitations/bulk', [FapiOrganizationInvitationController::class, 'bulkStore'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:manage')
            ->name('fapi.organizations.invitations.bulk');
        Route::post('/organizations/{organization_id}/invitations/{invitation_id}/revoke', [FapiOrganizationInvitationController::class, 'revoke'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:manage')
            ->name('fapi.organizations.invitations.revoke');

        // Per-org membership-request approve/reject (AU-7).
        Route::get('/organizations/{organization_id}/membership-requests', [FapiOrganizationMembershipRequestController::class, 'index'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:read')
            ->name('fapi.organizations.membership_requests.index');
        Route::post('/organizations/{organization_id}/membership-requests/{request_id}/accept', [FapiOrganizationMembershipRequestController::class, 'accept'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:manage')
            ->name('fapi.organizations.membership_requests.accept');
        Route::post('/organizations/{organization_id}/membership-requests/{request_id}/reject', [FapiOrganizationMembershipRequestController::class, 'reject'])
            ->middleware(EnsureOrgPermission::class.':org:sys_memberships:manage')
            ->name('fapi.organizations.membership_requests.reject');

        // Per-org domains (AU-13 / OA-1). Drives the <OrganizationProfile />
        // Domains section in sdk-react. BAPI hosts the verification-challenge
        // sub-resource — FAPI only exposes the CRUD on the domain rows.
        Route::get('/organizations/{organization_id}/domains', [FapiOrganizationDomainController::class, 'index'])
            ->middleware(EnsureOrgPermission::class.':org:sys_domains:read')
            ->name('fapi.organizations.domains.index');
        Route::post('/organizations/{organization_id}/domains', [FapiOrganizationDomainController::class, 'store'])
            ->middleware(EnsureOrgPermission::class.':org:sys_domains:manage')
            ->name('fapi.organizations.domains.store');
        Route::get('/organizations/{organization_id}/domains/{domain_id}', [FapiOrganizationDomainController::class, 'show'])
            ->middleware(EnsureOrgPermission::class.':org:sys_domains:read')
            ->name('fapi.organizations.domains.show');
        Route::patch('/organizations/{organization_id}/domains/{domain_id}', [FapiOrganizationDomainController::class, 'update'])
            ->middleware(EnsureOrgPermission::class.':org:sys_domains:manage')
            ->name('fapi.organizations.domains.update');
        Route::delete('/organizations/{organization_id}/domains/{domain_id}', [FapiOrganizationDomainController::class, 'destroy'])
            ->middleware(EnsureOrgPermission::class.':org:sys_domains:manage')
            ->name('fapi.organizations.domains.destroy');

        // Per-org enterprise SSO connections (AU-6 / OA-5). Drives the
        // <OrganizationProfile /> SSO section in sdk-react.
        Route::get('/organizations/{organization_id}/enterprise-connections', [OrganizationEnterpriseConnectionController::class, 'index'])
            ->middleware(EnsureOrgPermission::class.':org:sys_sso:manage')
            ->name('fapi.organizations.enterprise_connections.index');
        Route::post('/organizations/{organization_id}/enterprise-connections', [OrganizationEnterpriseConnectionController::class, 'store'])
            ->middleware(EnsureOrgPermission::class.':org:sys_sso:manage')
            ->name('fapi.organizations.enterprise_connections.store');
        Route::get('/organizations/{organization_id}/enterprise-connections/{enterprise_connection_id}', [OrganizationEnterpriseConnectionController::class, 'show'])
            ->middleware(EnsureOrgPermission::class.':org:sys_sso:manage')
            ->name('fapi.organizations.enterprise_connections.show');
        Route::patch('/organizations/{organization_id}/enterprise-connections/{enterprise_connection_id}', [OrganizationEnterpriseConnectionController::class, 'update'])
            ->middleware(EnsureOrgPermission::class.':org:sys_sso:manage')
            ->name('fapi.organizations.enterprise_connections.update');
        Route::delete('/organizations/{organization_id}/enterprise-connections/{enterprise_connection_id}', [OrganizationEnterpriseConnectionController::class, 'destroy'])
            ->middleware(EnsureOrgPermission::class.':org:sys_sso:manage')
            ->name('fapi.organizations.enterprise_connections.destroy');
        Route::post('/organizations/{organization_id}/enterprise-connections/{enterprise_connection_id}/test', [OrganizationEnterpriseConnectionController::class, 'test'])
            ->middleware(EnsureOrgPermission::class.':org:sys_sso:manage')
            ->name('fapi.organizations.enterprise_connections.test');

        // Per-org SCIM management (AU-10 / OA-7). Drives the
        // <OrganizationProfile /> Directory Sync section in sdk-react.
        Route::get('/organizations/{organization_id}/scim/tokens', [OrganizationScimController::class, 'listTokens'])
            ->middleware(EnsureOrgPermission::class.':org:sys_provisioning:manage')
            ->name('fapi.organizations.scim.tokens.index');
        Route::post('/organizations/{organization_id}/scim/tokens', [OrganizationScimController::class, 'issueToken'])
            ->middleware(EnsureOrgPermission::class.':org:sys_provisioning:manage')
            ->name('fapi.organizations.scim.tokens.store');
        Route::post('/organizations/{organization_id}/scim/tokens/{token_id}/revoke', [OrganizationScimController::class, 'revokeToken'])
            ->middleware(EnsureOrgPermission::class.':org:sys_provisioning:manage')
            ->name('fapi.organizations.scim.tokens.revoke');
        Route::get('/organizations/{organization_id}/scim/attribute-mappings', [OrganizationScimController::class, 'showAttributeMappings'])
            ->middleware(EnsureOrgPermission::class.':org:sys_provisioning:manage')
            ->name('fapi.organizations.scim.attribute_mappings.show');
        Route::put('/organizations/{organization_id}/scim/attribute-mappings', [OrganizationScimController::class, 'replaceAttributeMappings'])
            ->middleware(EnsureOrgPermission::class.':org:sys_provisioning:manage')
            ->name('fapi.organizations.scim.attribute_mappings.replace');
        Route::get('/organizations/{organization_id}/scim/endpoint', [OrganizationScimController::class, 'showEndpoint'])
            ->middleware(EnsureOrgPermission::class.':org:sys_provisioning:manage')
            ->name('fapi.organizations.scim.endpoint.show');

        // /v1/me org-related collections + active-org switching (AU-7).
        Route::get('/me/organization-memberships', [MeOrganizationController::class, 'listMemberships'])->name('fapi.me.organization_memberships');
        Route::get('/me/organization-invitations', [MeOrganizationController::class, 'listInvitations'])->name('fapi.me.organization_invitations');
        Route::post('/me/organization-invitations/{invitation_id}/accept', [MeOrganizationController::class, 'acceptInvitation'])->name('fapi.me.organization_invitations.accept');
        Route::get('/me/organization-membership-requests', [MeOrganizationController::class, 'listMembershipRequests'])->name('fapi.me.organization_membership_requests');
        Route::put('/me/active-organization', [MeOrganizationController::class, 'setActiveOrganization'])->name('fapi.me.active_organization');
    });
});

// Bare-root landing. For the `_admin` env this is where the operator
// arrives without a subpath — send them to the Dashboard. Tenant envs
// fall through to their configured home_url, or to /sign-in if none.
Route::withoutMiddleware([EnforceFapiOrigin::class])->get('/', function () {
    $env = app()->bound(Environment::class) ? app(Environment::class) : null;
    if ($env?->project?->is_admin_project) {
        return redirect(Url::dashboard());
    }
    $home = $env?->home_url;

    return redirect($home !== null && $home !== '' ? $home : Url::accountPortal($env, '/sign-in'));
})->name('fapi.root');

// Account Portal — Inertia + React pages mounted under the FAPI host root.
// These routes opt out of the FAPI Origin gate (they're top-level navigations
// from the browser, not state-changing AJAX) and bind the Inertia root view
// + shared bootstrap props via HandleAccountPortalInertia.
Route::withoutMiddleware([EnforceFapiOrigin::class])
    ->middleware(HandleAccountPortalInertia::class)
    ->group(function (): void {
        Route::get('/sign-in/{step?}', [AccountPortalController::class, 'signIn'])
            ->where('step', 'factor-one|factor-two|reset-password|sso-callback')
            ->name('account_portal.sign_in');
        Route::get('/sign-up/{step?}', [AccountPortalController::class, 'signUp'])
            ->where('step', 'verify-email-address|verify-phone-number|continue|sso-callback')
            ->name('account_portal.sign_up');
        Route::get('/user/{section?}', [AccountPortalController::class, 'userProfile'])
            ->name('account_portal.user');
        Route::get('/verify', [AccountPortalController::class, 'verify'])
            ->name('account_portal.verify');
        Route::post('/sign-out', [AccountPortalController::class, 'signOut'])
            ->name('account_portal.sign_out');

        // v0.2 org-facing pages (AU-12).
        Route::get('/organization-list', [AccountPortalController::class, 'organizationList'])
            ->name('account_portal.organization_list');
        Route::get('/create-organization', [AccountPortalController::class, 'createOrganization'])
            ->name('account_portal.create_organization');
        Route::get('/organization/{id?}/{tab?}', [AccountPortalController::class, 'organizationProfile'])
            ->where('tab', 'general|members|invitations|requests|domains')
            ->name('account_portal.organization_profile');

        // Dashboard Customization editor preview — operators mint a 5-min
        // token via POST /configure/appearance/preview (dashboard host) and
        // load that signed URL inside the editor iframe.
        Route::get('/_preview/appearance/{token}', [AppearancePreviewRenderController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{16,128}')
            ->name('account_portal.appearance_preview');

        // OAuth provider mode — consent screen for /oauth/authorize redirects
        // (AU-9). The accept/deny POSTs do JSON-only fetch from the page so
        // they sit outside the Inertia group below.
        Route::get('/oauth/consent/{request_id}', [OauthConsentController::class, 'show'])
            ->where('request_id', '[A-Za-z0-9_-]{16,128}')
            ->name('account_portal.oauth_consent');
    });

// OAuth consent accept/deny — top-level POSTs from the consent screen.
// JSON-only response shape (`{redirect_url}`) so the page can follow the
// redirect in JS. EnforceFapiOrigin opt-out because the consent screen is
// a top-level Inertia navigation context, not an embedded SDK call.
Route::withoutMiddleware([EnforceFapiOrigin::class])->group(function (): void {
    Route::post('/oauth/consent/{request_id}/accept', [OauthConsentController::class, 'accept'])
        ->where('request_id', '[A-Za-z0-9_-]{16,128}')
        ->name('fapi.oauth.consent.accept');
    Route::post('/oauth/consent/{request_id}/deny', [OauthConsentController::class, 'deny'])
        ->where('request_id', '[A-Za-z0-9_-]{16,128}')
        ->name('fapi.oauth.consent.deny');
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
