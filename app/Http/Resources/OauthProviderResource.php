<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OauthProvider;

/**
 * Public OauthProvider shape per OA-1. `client_secret` never appears in
 * any response (the model `$hidden`s it; this resource just doesn't emit
 * it). `redirect_uri` is always emitted — the operator must register it
 * with the IdP.
 */
final class OauthProviderResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(OauthProvider $provider): array
    {
        return [
            'object' => 'oauth_provider',
            'id' => $provider->id,
            'provider_kind' => $provider->provider_kind,
            'provider_key' => $provider->provider_key,
            'name' => $provider->name,
            'enabled' => (bool) $provider->enabled,
            'allow_sign_in' => (bool) $provider->allow_sign_in,
            'allow_sign_up' => (bool) $provider->allow_sign_up,
            'block_email_subaddresses' => (bool) $provider->block_email_subaddresses,
            'client_id' => (string) $provider->client_id,
            'scopes' => is_array($provider->scopes) ? array_values($provider->scopes) : [],
            'additional_authorization_params' => is_array($provider->additional_authorization_params)
                ? $provider->additional_authorization_params
                : [],
            'attribute_mapping' => is_array($provider->attribute_mapping)
                ? $provider->attribute_mapping
                : [],
            'issuer' => $provider->issuer,
            'discovery_endpoint' => $provider->discovery_endpoint,
            'authorization_endpoint' => $provider->authorization_endpoint,
            'token_endpoint' => $provider->token_endpoint,
            'userinfo_endpoint' => $provider->userinfo_endpoint,
            'jwks_uri' => $provider->jwks_uri,
            'id_token_signing_algs' => is_array($provider->id_token_signing_algs)
                ? array_values($provider->id_token_signing_algs)
                : [],
            'userinfo_method' => $provider->userinfo_method,
            'userinfo_auth' => $provider->userinfo_auth,
            'redirect_uri' => $provider->computeRedirectUri(),
            'created_at' => $provider->created_at?->getTimestampMs(),
            'updated_at' => $provider->updated_at?->getTimestampMs(),
        ];
    }
}
