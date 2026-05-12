<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\EnterpriseSso\EnterpriseConnectionService;
use App\Auth\EnterpriseSso\OidcConnectionService;
use App\Auth\ErrorCodes;
use App\Auth\Oauth\StateToken;
use App\Auth\Saml\SamlConnectionService;
use App\Models\Client;
use App\Models\EnterpriseConnection;
use App\Models\SignInAttempt;
use App\Models\Verification;
use App\Services\Verification\VerificationManager;
use App\Settings\EnterpriseSsoSettings;

/**
 * `enterprise_sso` + `saml` first-factor strategy. Mirrors
 * `OauthRedirectStrategy` (the "answer" lives on the IdP redirect
 * callback handled by `EnterpriseSsoCallbackController` /
 * `samlAcs`). `prepare()` resolves the right `EnterpriseConnection`,
 * builds the authorize URL (OIDC) or AuthnRequest (SAML), stashes
 * `state` + `nonce` + `code_verifier` for the callback to consume,
 * and writes the URL onto `Verification.external_verification_redirect_url`.
 */
final class EnterpriseSsoStrategy implements Strategy
{
    public function __construct(
        private readonly EnterpriseConnectionService $resolver,
        private readonly OidcConnectionService $oidc,
        private readonly SamlConnectionService $saml,
        private readonly VerificationManager $verifications,
    ) {}

    public function name(): string
    {
        return 'enterprise_sso';
    }

    public function requiresPrepare(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        $strategyKey = (string) ($params['strategy'] ?? Verification::STRATEGY_ENTERPRISE_SSO);
        $client = $params['client'] ?? null;
        $clientId = $client instanceof Client ? $client->id : '';

        $conn = $this->resolveConnection($attempt, $params);
        if (! $conn instanceof EnterpriseConnection) {
            return $conn;
        }

        // Strict-semantic toggle (AU-14): existing linked accounts continue
        // to work, but new SignIns drop through when the env flips off.
        $settings = EnterpriseSsoSettings::fromUserSettings(is_array($attempt->environment?->user_settings) ? $attempt->environment->user_settings : []);
        if (! $settings->enabled) {
            return StrategyResult::fail($attempt, ErrorCodes::STRATEGY_NOT_SUPPORTED, 'Enterprise SSO is disabled on this environment.', 422);
        }

        $verification = $this->verifications->start($attempt, $strategyKey, StateToken::TTL_SECONDS);
        $nonce = bin2hex(random_bytes(16));

        if ($conn->isOidc()) {
            $authorize = $this->oidc->buildAuthorizeUrl($conn, state: $verification->id, nonce: $nonce);
            $state = StateToken::mint(
                environmentId: $attempt->environment_id,
                providerKey: 'enterprise:'.$conn->id,
                verificationId: $verification->id,
                clientId: $clientId,
                attemptId: $attempt->id,
                attemptKind: 'sign_in',
                redirectUrl: is_string($params['redirect_url'] ?? null) ? (string) $params['redirect_url'] : null,
                redirectUrlComplete: is_string($params['redirect_url_complete'] ?? null) ? (string) $params['redirect_url_complete'] : null,
                nonce: $nonce,
                extra: ['conn' => $conn->id, 'cv' => $authorize['code_verifier']],
            );
            // Rebuild the URL with the encrypted state token in place of
            // the verification id we used for `buildAuthorizeUrl`'s state
            // placeholder above.
            $authorizeUrl = $this->swapStateInUrl($authorize['authorize_url'], $verification->id, $state);

            $verification->forceFill([
                'external_verification_redirect_url' => $authorizeUrl,
                'nonce' => $nonce,
            ])->save();

            return StrategyResult::ok($attempt, verification: $verification);
        }

        // SAML — mint AuthnRequest with our verification id as RelayState.
        $state = StateToken::mint(
            environmentId: $attempt->environment_id,
            providerKey: 'enterprise:'.$conn->id,
            verificationId: $verification->id,
            clientId: $clientId,
            attemptId: $attempt->id,
            attemptKind: 'sign_in',
            redirectUrl: is_string($params['redirect_url'] ?? null) ? (string) $params['redirect_url'] : null,
            redirectUrlComplete: is_string($params['redirect_url_complete'] ?? null) ? (string) $params['redirect_url_complete'] : null,
            nonce: $nonce,
            extra: ['conn' => $conn->id],
        );
        $samlRequestB64 = $this->saml->buildAuthnRequest($conn, relayState: $state);
        $separator = str_contains((string) $conn->saml_sso_url, '?') ? '&' : '?';
        $authorizeUrl = (string) $conn->saml_sso_url.$separator.http_build_query([
            'SAMLRequest' => $samlRequestB64,
            'RelayState' => $state,
        ]);

        $verification->forceFill([
            'external_verification_redirect_url' => $authorizeUrl,
            'nonce' => $nonce,
        ])->save();

        return StrategyResult::ok($attempt, verification: $verification);
    }

    public function attempt(SignInAttempt $attempt, array $params): StrategyResult
    {
        return StrategyResult::fail(
            $attempt,
            ErrorCodes::PREPARE_NOT_REQUIRED,
            'enterprise_sso completes via the /v1/enterprise-sso-callback OIDC redirect or /v1/saml/{connection_id}/acs SAML POST, not the answer endpoint.',
            422,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function resolveConnection(SignInAttempt $attempt, array $params): EnterpriseConnection|StrategyResult
    {
        $env = $attempt->environment;
        if ($env === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_PARAM_NIL, 'attempt has no environment.', 422);
        }

        $explicitId = $params['enterprise_connection_id'] ?? null;
        if (is_string($explicitId) && $explicitId !== '') {
            $conn = EnterpriseConnection::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->where('id', $explicitId)
                ->where('enabled', true)
                ->first();
            if ($conn === null) {
                return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'No enabled enterprise connection matches that id.', 422);
            }

            return $conn;
        }

        $identifier = (string) $attempt->identifier;
        $conn = $this->resolver->findByIdentifierDomain($env, $identifier);
        if ($conn === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'No enterprise connection routes the identifier domain.', 422);
        }

        return $conn;
    }

    /**
     * Replace the placeholder `state=<id>` (we used the verification id to
     * pre-mint the URL) with the actual encrypted state token. Cheaper
     * than re-running the whole OIDC URL build.
     */
    private function swapStateInUrl(string $url, string $oldState, string $newState): string
    {
        return str_replace(
            'state='.rawurlencode($oldState),
            'state='.rawurlencode($newState),
            $url,
        );
    }
}
