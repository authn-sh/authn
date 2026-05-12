<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\EnterpriseSso\EnterpriseConnectionService;
use App\Auth\EnterpriseSso\OidcConnectionService;
use App\Auth\Oauth\StateToken;
use App\Auth\Saml\SamlConnectionService;
use App\Auth\Saml\SamlException;
use App\Models\Challenge;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\OrganizationDomain;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Session;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Services\Sessions\SessionLifecycle;
use App\Settings\EnterpriseSsoSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Callback handlers for Enterprise SSO:
 *
 *   GET  /v1/enterprise-sso-callback    — OIDC redirect-back. `state`
 *                                          is the encrypted `StateToken`,
 *                                          `code` is the IdP-issued auth
 *                                          code. We exchange + verify the
 *                                          id_token, provision the User
 *                                          + EnterpriseAccount, close the
 *                                          parent SignIn.
 *   POST /v1/saml/{connection_id}/acs   — SAML AssertionConsumerService.
 *                                          `SAMLResponse` is form-posted;
 *                                          `RelayState` carries the same
 *                                          encrypted `StateToken`.
 *
 * Both paths converge on `finalize()` which does the
 * provisionUser → close-SignIn → OrganizationMembership-autojoin work.
 */
final class EnterpriseSsoCallbackController
{
    public function __construct(
        private readonly OidcConnectionService $oidc,
        private readonly SamlConnectionService $saml,
        private readonly EnterpriseConnectionService $connections,
        private readonly SessionLifecycle $sessions,
    ) {}

    public function oidcCallback(Request $request): RedirectResponse
    {
        $stateToken = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $state = StateToken::unwrap($stateToken);
        if ($state === null) {
            return $this->failTopLevel(null, 'state_invalid', 'state token is missing or expired.');
        }

        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        if ($env === null || $env->id !== ($state['env'] ?? null)) {
            return $this->failTopLevel($state['ru'] ?? null, 'state_env_mismatch', 'state.environment_id does not match.');
        }

        $verification = $this->loadVerification($state['v'] ?? '', $env->id);
        if ($verification === null) {
            return $this->failTopLevel($state['ru'] ?? null, 'verification_not_found', 'verification row missing.');
        }

        $connId = (string) ($state['conn'] ?? '');
        $conn = EnterpriseConnection::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $connId)
            ->first();
        if ($conn === null || ! $conn->isOidc()) {
            return $this->failWithVerification($state['ru'] ?? null, $verification, 'enterprise_connection_not_found', 'Connection missing or wrong protocol.');
        }

        $codeVerifier = (string) ($state['cv'] ?? '');
        if ($code === '' || $codeVerifier === '') {
            return $this->failWithVerification($state['ru'] ?? null, $verification, 'oidc_callback_incomplete', 'IdP did not return an authorization code.');
        }

        try {
            $tokens = $this->oidc->exchangeCode($conn, $code, $codeVerifier);
        } catch (Throwable $e) {
            Log::warning('enterprise_sso.oidc.token_exchange_failed', ['conn' => $conn->id, 'error' => $e->getMessage()]);

            return $this->failWithVerification($state['ru'] ?? null, $verification, 'oidc_token_exchange_failed', $e->getMessage());
        }

        $idToken = (string) ($tokens['id_token'] ?? '');
        if ($idToken === '') {
            return $this->failWithVerification($state['ru'] ?? null, $verification, 'oidc_id_token_missing', 'IdP did not return an id_token.');
        }
        $claims = $this->oidc->verifyIdToken($conn, $idToken, expectedNonce: (string) ($state['n'] ?? ''));
        if ($claims === null) {
            return $this->failWithVerification($state['ru'] ?? null, $verification, 'oidc_id_token_invalid', 'id_token verification failed.');
        }

        $identity = [
            'provider_user_id' => (string) ($claims['sub'] ?? ''),
            'email_address' => isset($claims['email']) ? strtolower((string) $claims['email']) : null,
            'first_name' => isset($claims['given_name']) ? (string) $claims['given_name'] : null,
            'last_name' => isset($claims['family_name']) ? (string) $claims['family_name'] : null,
            'id_token' => $idToken,
            'raw_attributes' => $claims,
        ];

        return $this->finalize($env, $conn, $verification, $state, $identity, $claims['amr'] ?? null);
    }

    public function samlAcs(Request $request): RedirectResponse
    {
        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        if ($env === null) {
            return $this->failTopLevel(null, 'environment_not_resolved', 'Environment missing on SAML ACS.');
        }

        $connId = (string) $request->route('connection_id');
        $conn = EnterpriseConnection::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $connId)
            ->first();
        if ($conn === null || ! $conn->isSaml()) {
            return $this->failTopLevel(null, 'enterprise_connection_not_found', 'Connection missing or wrong protocol.');
        }

        $samlResponseB64 = (string) $request->input('SAMLResponse', '');
        $relayState = (string) $request->input('RelayState', '');
        $state = StateToken::unwrap($relayState);
        if ($state === null) {
            return $this->failTopLevel(null, 'state_invalid', 'RelayState is missing or expired.');
        }
        if (($state['conn'] ?? null) !== $conn->id) {
            return $this->failTopLevel($state['ru'] ?? null, 'state_connection_mismatch', 'RelayState.connection does not match the ACS URL.');
        }

        $verification = $this->loadVerification($state['v'] ?? '', $env->id);
        if ($verification === null) {
            return $this->failTopLevel($state['ru'] ?? null, 'verification_not_found', 'verification row missing.');
        }

        try {
            $assertion = $this->saml->parseSamlResponse($samlResponseB64, $conn);
        } catch (SamlException $e) {
            Log::warning('enterprise_sso.saml.parse_failed', ['conn' => $conn->id, 'reason' => $e->reason]);

            return $this->failWithVerification($state['ru'] ?? null, $verification, $e->reason, $e->getMessage());
        } catch (Throwable $e) {
            return $this->failWithVerification($state['ru'] ?? null, $verification, 'saml_parse_failed', $e->getMessage());
        }

        $identity = [
            'provider_user_id' => $assertion->nameId,
            'email_address' => $this->extractEmailFromSamlAttributes($assertion->attributes),
            'first_name' => $assertion->firstAttribute('givenName')
                ?? $assertion->firstAttribute('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname'),
            'last_name' => $assertion->firstAttribute('surname')
                ?? $assertion->firstAttribute('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname'),
            'id_token' => null,
            'raw_attributes' => $assertion->attributes,
        ];

        return $this->finalize($env, $conn, $verification, $state, $identity, $assertion->authnContextClassRef);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array{provider_user_id:string, email_address:?string, first_name:?string, last_name:?string, id_token:?string, raw_attributes:array<string, mixed>}  $identity
     */
    private function finalize(
        Environment $env,
        EnterpriseConnection $conn,
        Verification $verification,
        array $state,
        array $identity,
        mixed $authnContextSignal,
    ): RedirectResponse {
        if ($identity['provider_user_id'] === '') {
            return $this->failWithVerification($state['ru'] ?? null, $verification, 'subject_missing', 'No subject (NameID / sub) on the IdP assertion.');
        }

        $attempt = $this->loadAttempt($state, $env->id);
        if ($attempt === null) {
            return $this->failWithVerification($state['ru'] ?? null, $verification, 'sign_in_not_found', 'Parent SignInAttempt missing.');
        }

        try {
            $provisioned = $this->connections->provisionUserFromIdentity($conn, $identity);
        } catch (Throwable $e) {
            return $this->failWithVerification($state['ru'] ?? null, $verification, 'provision_failed', $e->getMessage());
        }

        $session = DB::transaction(function () use ($env, $attempt, $verification, $conn, $provisioned, $authnContextSignal, $identity): Session {
            $verification->forceFill([
                'status' => Verification::STATUS_VERIFIED,
                'verified_at' => now(),
            ])->save();

            $challenge = Challenge::query()->withoutGlobalScopes()
                ->where('parent_type', Challenge::PARENT_SIGN_IN)
                ->where('parent_id', $attempt->id)
                ->latest('id')
                ->first();
            if ($challenge !== null) {
                $challenge->forceFill(['status' => Challenge::STATUS_VERIFIED])->save();
            }

            $identifierForAutojoin = is_string($attempt->identifier) && $attempt->identifier !== ''
                ? $attempt->identifier
                : $identity['email_address'];
            $this->autojoinOrgIfApplicable($conn, $provisioned['user'], $identifierForAutojoin);

            $attempt->status = SignInAttempt::STATUS_COMPLETE;
            $session = $this->sessions->mint($env, $provisioned['user'], $attempt);
            $attempt->created_session_id = $session->id;
            $attempt->save();

            $this->stampMfaSatisfiedIfApplicable($env, $session, $authnContextSignal);

            return $session;
        });

        $redirectUrlComplete = is_string($state['ruc'] ?? null) ? (string) $state['ruc'] : null;
        $redirectUrl = is_string($state['ru'] ?? null) ? (string) $state['ru'] : null;
        $target = $redirectUrlComplete ?? $redirectUrl ?? '/';
        unset($session); // touched so PHPStan is happy

        return redirect()->away($target);
    }

    private function autojoinOrgIfApplicable(EnterpriseConnection $conn, User $user, ?string $identifier = null): void
    {
        // Path A — org-scoped connection with a default_role: always auto-join.
        if ($conn->organization_id !== null && is_string($conn->default_role) && $conn->default_role !== '') {
            $this->createMembershipIfMissing($conn->environment_id, $conn->organization_id, $conn->default_role, $user);
        }

        // Path B (AU-8) — instance-wide connection but the identifier's
        // domain matches a verified `OrganizationDomain` with an opt-in
        // enrollment mode. Honour the org's enrollment_mode:
        //   automatic_invitation → join immediately
        //   automatic_suggestion → leave as a suggested org (surfaced via
        //                          User.suggested_organizations[] at the
        //                          /v1/me layer; nothing to do here)
        //   manual_invitation    → no auto-action
        if ($identifier !== null && $conn->default_role !== null && $conn->default_role !== '') {
            $domain = $this->extractDomain($identifier);
            if ($domain !== null) {
                $orgDomains = OrganizationDomain::query()
                    ->withoutGlobalScopes()
                    ->where('environment_id', $conn->environment_id)
                    ->where('name', $domain)
                    ->where('verified', true)
                    ->get();
                foreach ($orgDomains as $orgDomain) {
                    if ($orgDomain->enrollment_mode === OrganizationDomain::MODE_AUTOMATIC_INVITATION) {
                        $this->createMembershipIfMissing($conn->environment_id, $orgDomain->organization_id, $conn->default_role, $user);
                    }
                }
            }
        }
    }

    private function createMembershipIfMissing(string $environmentId, string $organizationId, string $roleKey, User $user): void
    {
        $existing = OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->exists();
        if ($existing) {
            return;
        }
        $role = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $environmentId)
            ->where('key', $roleKey)
            ->first();
        if ($role === null) {
            return;
        }
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'environment_id' => $environmentId,
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

    private function extractDomain(string $identifier): ?string
    {
        $at = strrpos($identifier, '@');
        if ($at === false) {
            return null;
        }
        $domain = strtolower(trim(substr($identifier, $at + 1)));

        return $domain === '' ? null : $domain;
    }

    /**
     * AU-14: when the env opts in and the IdP advertises MFA, stamp the
     * session as second-factor-satisfied.
     */
    private function stampMfaSatisfiedIfApplicable(Environment $env, Session $session, mixed $authnContextSignal): void
    {
        $settings = EnterpriseSsoSettings::fromUserSettings(is_array($env->user_settings) ? $env->user_settings : []);
        if (! $settings->enterpriseSsoCountsAsMfa) {
            return;
        }
        $signalsMfa = false;
        if (is_array($authnContextSignal)) {
            foreach ($authnContextSignal as $entry) {
                if (is_string($entry) && strtolower($entry) === 'mfa') {
                    $signalsMfa = true;
                    break;
                }
            }
        } elseif (is_string($authnContextSignal)) {
            // SAML AuthnContextClassRef — accept any URN containing "mfa" or
            // the spec's MultiFactor PasswordProtectedTransport variants.
            $lower = strtolower($authnContextSignal);
            $signalsMfa = str_contains($lower, 'mfa')
                || str_contains($lower, 'multifactor')
                || str_contains($lower, 'multi-factor');
        }
        if (! $signalsMfa) {
            return;
        }
        if (Schema::hasColumn('sessions', 'second_factor_satisfied_at')) {
            $session->forceFill(['second_factor_satisfied_at' => now()])->save();
        }
    }

    /**
     * @param  array<string, list<string>>  $attributes
     */
    private function extractEmailFromSamlAttributes(array $attributes): ?string
    {
        foreach (['email', 'mail', 'emailAddress', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'] as $key) {
            if (isset($attributes[$key][0]) && filter_var($attributes[$key][0], FILTER_VALIDATE_EMAIL)) {
                return strtolower((string) $attributes[$key][0]);
            }
        }

        return null;
    }

    private function loadVerification(mixed $id, string $environmentId): ?Verification
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Verification::query()->withoutGlobalScopes()
            ->where('id', $id)
            ->where('environment_id', $environmentId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function loadAttempt(array $state, string $environmentId): ?SignInAttempt
    {
        $id = $state['a'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return SignInAttempt::query()->withoutGlobalScopes()
            ->where('id', $id)
            ->where('environment_id', $environmentId)
            ->first();
    }

    private function failTopLevel(?string $redirectUrl, string $code, string $message): RedirectResponse
    {
        return $this->renderError($redirectUrl, $code, $message);
    }

    private function failWithVerification(?string $redirectUrl, Verification $verification, string $code, string $message): RedirectResponse
    {
        $verification->forceFill([
            'status' => Verification::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => $message,
        ])->save();

        return $this->renderError($redirectUrl, $code, $message);
    }

    private function renderError(?string $redirectUrl, string $code, string $message): RedirectResponse
    {
        $target = ($redirectUrl ?? '/').(str_contains($redirectUrl ?? '', '?') ? '&' : '?').'__authn_error='.urlencode($code);

        return redirect()->away($target);
    }
}
