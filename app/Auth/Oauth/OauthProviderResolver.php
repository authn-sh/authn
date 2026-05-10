<?php

declare(strict_types=1);

namespace App\Auth\Oauth;

use App\Auth\Oauth\Exceptions\OauthDiscoveryFailedException;
use App\Models\OauthProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Layered defaults for an OauthProvider row:
 *   1. preset (when `provider_kind = 'preset'` and the key matches the
 *      `PresetRegistry`)
 *   2. cached discovery (when `provider_kind = 'custom_oidc'` and the
 *      cache is fresh, or after a refresh)
 *   3. operator-set values on the row itself.
 *
 * Stored values always win — operators can override anything from the
 * preset (e.g. swap a custom userinfo endpoint).
 */
final class OauthProviderResolver
{
    /**
     * Time-to-live for cached `custom_oidc` discovery (PLAN §4.6).
     */
    public const DISCOVERY_TTL_SECONDS = 300;

    /**
     * `<our shape> => <userinfo claim path>` defaults applied to any
     * non-preset OIDC provider whose row has empty `attribute_mapping`.
     *
     * @var array<string, string>
     */
    public const DEFAULT_OIDC_ATTRIBUTE_MAPPING = [
        'email' => 'email',
        'email_verified' => 'email_verified',
        'first_name' => 'given_name',
        'last_name' => 'family_name',
        'image_url' => 'picture',
    ];

    public function __construct(private readonly PresetRegistry $presets) {}

    public function resolve(OauthProvider $provider): ResolvedProvider
    {
        if ($provider->provider_kind === OauthProvider::KIND_PRESET) {
            return $this->resolvePreset($provider);
        }

        if ($provider->provider_kind === OauthProvider::KIND_CUSTOM_OIDC) {
            return $this->resolveCustomOidc($provider);
        }

        return $this->resolveCustomOauth2($provider);
    }

    /**
     * Fetch + validate `<issuer>/.well-known/openid-configuration`.
     * Caller is expected to persist the returned shape onto the row;
     * this method is pure (no DB writes), so the BAPI controller can
     * preview discovery results without committing them.
     *
     * @return array{
     *     authorization_endpoint:string,
     *     token_endpoint:string,
     *     userinfo_endpoint:string,
     *     jwks_uri:?string,
     *     id_token_signing_algs:list<string>,
     * }
     *
     * @throws OauthDiscoveryFailedException
     */
    public function discoverEndpoints(string $issuer): array
    {
        $base = rtrim($issuer, '/');
        $url = $base.'/.well-known/openid-configuration';

        try {
            $response = Http::acceptJson()->timeout(10)->get($url);
        } catch (ConnectionException $e) {
            throw new OauthDiscoveryFailedException($issuer, null, null, $e->getMessage());
        } catch (Throwable $e) {
            throw new OauthDiscoveryFailedException($issuer, null, null, $e->getMessage());
        }

        if (! $response->successful()) {
            $body = mb_substr((string) $response->body(), 0, 512);
            throw new OauthDiscoveryFailedException(
                $issuer,
                $response->status(),
                $body,
                "Discovery {$url} returned HTTP {$response->status()}",
            );
        }

        $document = $response->json();
        if (! is_array($document)) {
            throw new OauthDiscoveryFailedException(
                $issuer,
                $response->status(),
                mb_substr((string) $response->body(), 0, 512),
                "Discovery {$url} did not return a JSON object",
            );
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $required) {
            if (! is_string($document[$required] ?? null) || $document[$required] === '') {
                throw new OauthDiscoveryFailedException(
                    $issuer,
                    $response->status(),
                    mb_substr((string) $response->body(), 0, 512),
                    "Discovery {$url} missing required field {$required}",
                );
            }
        }

        $idTokenAlgs = $document['id_token_signing_alg_values_supported'] ?? [];
        $idTokenAlgs = is_array($idTokenAlgs) ? array_values(array_filter($idTokenAlgs, 'is_string')) : [];

        return [
            'authorization_endpoint' => (string) $document['authorization_endpoint'],
            'token_endpoint' => (string) $document['token_endpoint'],
            'userinfo_endpoint' => (string) $document['userinfo_endpoint'],
            'jwks_uri' => is_string($document['jwks_uri'] ?? null) ? (string) $document['jwks_uri'] : null,
            'id_token_signing_algs' => $idTokenAlgs,
        ];
    }

    /**
     * Whether the row's cached discovery data is still fresh enough to
     * skip a refresh on the next resolve. `null` cached_at means "never
     * fetched" → always stale.
     */
    public function discoveryIsStale(OauthProvider $provider): bool
    {
        if ($provider->discovery_cached_at === null) {
            return true;
        }

        return $provider->discovery_cached_at->getTimestamp() + self::DISCOVERY_TTL_SECONDS < now()->getTimestamp();
    }

    private function resolvePreset(OauthProvider $provider): ResolvedProvider
    {
        $preset = $this->presets->get($provider->provider_key);
        if ($preset === null) {
            // Fall through as if it were a custom OAuth2 row — the
            // operator presumably renamed the preset row's key after
            // creation. Stored endpoints win regardless.
            return $this->resolveCustomOauth2($provider);
        }

        $scopes = $this->emptyArrayFallback($provider->scopes, $preset->defaultScopes());
        $mapping = $this->emptyArrayFallback($provider->attribute_mapping, $preset->defaultAttributeMapping());
        $authParams = $this->emptyArrayFallback(
            $provider->additional_authorization_params,
            $preset->additionalAuthorizationParams(),
        );
        $idTokenAlgs = $provider->id_token_signing_algs ?: $preset->idTokenSigningAlgs();

        return new ResolvedProvider(
            provider: $provider,
            authorizationEndpoint: $provider->authorization_endpoint ?: $preset->authorizationEndpoint(),
            tokenEndpoint: $provider->token_endpoint ?: $preset->tokenEndpoint(),
            userinfoEndpoint: $provider->userinfo_endpoint ?: $preset->userinfoEndpoint(),
            jwksUri: $provider->jwks_uri ?: $preset->jwksUri(),
            scopes: array_values($scopes),
            attributeMapping: $mapping,
            additionalAuthorizationParams: $authParams,
            idTokenSigningAlgs: array_values($idTokenAlgs),
            userinfoMethod: $provider->userinfo_method ?: $preset->userinfoMethod(),
            userinfoAuth: $provider->userinfo_auth ?: $preset->userinfoAuth(),
        );
    }

    private function resolveCustomOidc(OauthProvider $provider): ResolvedProvider
    {
        $endpoints = [
            'authorization_endpoint' => $provider->authorization_endpoint,
            'token_endpoint' => $provider->token_endpoint,
            'userinfo_endpoint' => $provider->userinfo_endpoint,
            'jwks_uri' => $provider->jwks_uri,
            'id_token_signing_algs' => $provider->id_token_signing_algs ?? [],
        ];

        if ($provider->issuer !== null && $this->discoveryIsStale($provider)) {
            try {
                $endpoints = $this->discoverEndpoints($provider->issuer);
                $provider->forceFill([
                    'authorization_endpoint' => $endpoints['authorization_endpoint'],
                    'token_endpoint' => $endpoints['token_endpoint'],
                    'userinfo_endpoint' => $endpoints['userinfo_endpoint'],
                    'jwks_uri' => $endpoints['jwks_uri'],
                    'id_token_signing_algs' => $endpoints['id_token_signing_algs'],
                    'discovery_cached_at' => now(),
                ])->save();
            } catch (OauthDiscoveryFailedException $e) {
                if ($provider->authorization_endpoint === null || $provider->token_endpoint === null) {
                    throw $e;
                }
                // Soft-fail: keep serving the last-good cached endpoints.
            }
        }

        $mapping = $this->emptyArrayFallback($provider->attribute_mapping, self::DEFAULT_OIDC_ATTRIBUTE_MAPPING);

        return new ResolvedProvider(
            provider: $provider,
            authorizationEndpoint: (string) $endpoints['authorization_endpoint'],
            tokenEndpoint: (string) $endpoints['token_endpoint'],
            userinfoEndpoint: (string) $endpoints['userinfo_endpoint'],
            jwksUri: $endpoints['jwks_uri'] !== null ? (string) $endpoints['jwks_uri'] : null,
            scopes: array_values($provider->scopes ?: ['openid']),
            attributeMapping: $mapping,
            additionalAuthorizationParams: $provider->additional_authorization_params ?: [],
            idTokenSigningAlgs: array_values($endpoints['id_token_signing_algs']),
            userinfoMethod: $provider->userinfo_method ?: 'GET',
            userinfoAuth: $provider->userinfo_auth ?: 'bearer',
        );
    }

    private function resolveCustomOauth2(OauthProvider $provider): ResolvedProvider
    {
        return new ResolvedProvider(
            provider: $provider,
            authorizationEndpoint: (string) $provider->authorization_endpoint,
            tokenEndpoint: (string) $provider->token_endpoint,
            userinfoEndpoint: (string) $provider->userinfo_endpoint,
            jwksUri: $provider->jwks_uri,
            scopes: array_values($provider->scopes ?: []),
            attributeMapping: $provider->attribute_mapping ?: [],
            additionalAuthorizationParams: $provider->additional_authorization_params ?: [],
            idTokenSigningAlgs: array_values($provider->id_token_signing_algs ?: []),
            userinfoMethod: $provider->userinfo_method ?: 'GET',
            userinfoAuth: $provider->userinfo_auth ?: 'bearer',
        );
    }

    /**
     * @template T
     *
     * @param  ?array<array-key, T>  $stored
     * @param  array<array-key, T>  $fallback
     * @return array<array-key, T>
     */
    private function emptyArrayFallback(?array $stored, array $fallback): array
    {
        return ($stored !== null && $stored !== []) ? $stored : $fallback;
    }
}
