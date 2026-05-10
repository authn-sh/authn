<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\ErrorCodes;
use App\Auth\Oauth\OauthProviderResolver;
use App\Auth\Oauth\ResolvedProvider;
use App\Auth\Oauth\StateToken;
use App\Models\Client;
use App\Models\OauthProvider;
use App\Models\SignInAttempt;
use App\Models\Verification;
use App\Services\Verification\VerificationManager;

/**
 * `oauth_<provider_key>` first-factor strategy. The "answer" lives on
 * the IdP redirect callback — `OauthCallbackController` flips the
 * Verification + parent attempt to `verified` / `complete` after the
 * `state` round-trip, code exchange, and userinfo lookup.
 *
 * `prepare()` mints the authorize URL the SDK redirects the browser to,
 * stashes a server-issued `state` token (encrypted via app-key) so the
 * callback can find the originating attempt, and writes the URL onto
 * the Verification's `external_verification_redirect_url`.
 *
 * Strategy `name()` returns the placeholder `oauth_*`; the dispatcher
 * resolves any concrete `oauth_<provider_key>` to this single instance.
 */
final class OauthRedirectStrategy implements Strategy
{
    public function __construct(
        private readonly OauthProviderResolver $resolver,
        private readonly VerificationManager $verifications,
    ) {}

    public function name(): string
    {
        return 'oauth_*';
    }

    public function requiresPrepare(): bool
    {
        return true;
    }

    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        $providerKey = $params['provider_key'] ?? null;
        if (! is_string($providerKey) || $providerKey === '') {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_PARAM_NIL, 'provider_key is required.', 422);
        }

        $row = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('provider_key', $providerKey)
            ->where('enabled', true)
            ->first();
        if ($row === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'No enabled OAuth provider matches that key.', 422);
        }

        $resolved = $this->resolver->resolve($row);

        $verification = $this->verifications->start(
            $attempt,
            'oauth_'.$providerKey,
            StateToken::TTL_SECONDS,
        );

        $client = $params['client'] ?? null;
        $clientId = $client instanceof Client ? $client->id : '';
        $nonce = bin2hex(random_bytes(16));

        $state = StateToken::mint(
            environmentId: $attempt->environment_id,
            providerKey: $providerKey,
            verificationId: $verification->id,
            clientId: $clientId,
            attemptId: $attempt->id,
            attemptKind: 'sign_in',
            redirectUrl: is_string($params['redirect_url'] ?? null) ? (string) $params['redirect_url'] : null,
            redirectUrlComplete: is_string($params['redirect_url_complete'] ?? null) ? (string) $params['redirect_url_complete'] : null,
            nonce: $nonce,
        );

        $authorizeUrl = $this->buildAuthorizeUrl($resolved, $row, $state, $nonce);

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
            'oauth_<provider_key> completes via the /v1/oauth-callback/<provider_key> redirect, not the answer endpoint.',
            422,
        );
    }

    /**
     * @param  array<string, mixed>  $extraParams
     */
    private function buildAuthorizeUrl(ResolvedProvider $resolved, OauthProvider $row, string $state, string $nonce): string
    {
        $params = array_merge([
            'response_type' => 'code',
            'client_id' => $row->client_id,
            'redirect_uri' => $row->computeRedirectUri(),
            'scope' => implode(' ', $resolved->scopes),
            'state' => $state,
            'nonce' => $nonce,
        ], $resolved->additionalAuthorizationParams);

        $separator = str_contains($resolved->authorizationEndpoint, '?') ? '&' : '?';

        return $resolved->authorizationEndpoint.$separator.http_build_query($params);
    }
}
