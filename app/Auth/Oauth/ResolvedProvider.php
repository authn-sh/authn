<?php

declare(strict_types=1);

namespace App\Auth\Oauth;

use App\Models\OauthProvider;

/**
 * Read-only view of an OauthProvider with all preset / discovery defaults
 * applied. Returned by `OauthProviderResolver::resolve()`. Callers
 * (BAPI test endpoint, FAPI callback) work against this struct rather
 * than the raw model so the layered defaults are computed once.
 */
final class ResolvedProvider
{
    /**
     * @param  array<int, string>  $scopes
     * @param  array<string, string>  $attributeMapping
     * @param  array<string, mixed>  $additionalAuthorizationParams
     * @param  list<string>  $idTokenSigningAlgs
     */
    public function __construct(
        public readonly OauthProvider $provider,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly string $userinfoEndpoint,
        public readonly ?string $jwksUri,
        public readonly array $scopes,
        public readonly array $attributeMapping,
        public readonly array $additionalAuthorizationParams,
        public readonly array $idTokenSigningAlgs,
        public readonly string $userinfoMethod,
        public readonly string $userinfoAuth,
        public readonly ?string $issuer = null,
    ) {}
}
