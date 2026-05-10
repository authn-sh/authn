<?php

declare(strict_types=1);

use App\Http\Controllers\AccountPortal\AccountPortalController;
use App\Http\Controllers\Fapi\ChallengeController;
use App\Http\Controllers\Fapi\ClientController;
use App\Http\Controllers\Fapi\EnvironmentController;
use App\Http\Controllers\Fapi\MagicLinkController;
use App\Http\Controllers\Fapi\MeController;
use App\Http\Controllers\Fapi\MeOrganizationController;
use App\Http\Controllers\Fapi\OrganizationController as FapiOrganizationController;
use App\Http\Controllers\Fapi\OrganizationInvitationController as FapiOrganizationInvitationController;
use App\Http\Controllers\Fapi\OrganizationMembershipController as FapiOrganizationMembershipController;
use App\Http\Controllers\Fapi\OrganizationMembershipRequestController as FapiOrganizationMembershipRequestController;
use App\Http\Controllers\Fapi\PingController;
use App\Http\Controllers\Fapi\SessionsController;
use App\Http\Controllers\Fapi\SessionTokenController;
use App\Http\Controllers\Fapi\SignInController;
use App\Http\Controllers\Fapi\SignUpController;
use App\Http\Controllers\WellKnown\JwksController;
use App\Http\Controllers\WellKnown\OpenIdConfigurationController;
use App\Http\Middleware\AuthenticateSessionToken;
use App\Http\Middleware\EnforceFapiOrigin;
use App\Http\Middleware\EnsureOrgPermission;
use App\Http\Middleware\HandleAccountPortalInertia;
use App\Http\Middleware\ResolveClientFromCookie;
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

        // Magic-link click handler (AU-10). Top-level browser navigation
        // from the email — no Origin header, no Client cookie required.
        Route::get('/client/magic-link/redeem', [MagicLinkController::class, 'redeem'])->name('fapi.magic_link.redeem');
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
        Route::get('/me/email-addresses/{eid}', [MeController::class, 'showEmail'])->name('fapi.me.emails.show');
        Route::patch('/me/email-addresses/{eid}', [MeController::class, 'updateEmail'])->name('fapi.me.emails.update');
        Route::delete('/me/email-addresses/{eid}', [MeController::class, 'deleteEmail'])->name('fapi.me.emails.destroy');
        Route::post('/me/email-addresses/{eid}/prepare-verification', [MeController::class, 'prepareEmailVerification'])->name('fapi.me.emails.prepare_verification');
        Route::post('/me/email-addresses/{eid}/attempt-verification', [MeController::class, 'attemptEmailVerification'])->name('fapi.me.emails.attempt_verification');

        Route::get('/me/sessions', [MeController::class, 'listSessions'])->name('fapi.me.sessions.list');
        Route::post('/me/change-password', [MeController::class, 'changePassword'])->name('fapi.me.change_password');

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
