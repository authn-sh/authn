<?php

declare(strict_types=1);

namespace App\Auth\Oauth;

use App\Models\Environment;
use App\Models\OauthProvider;

/**
 * Seeds the four preset OauthProvider rows on environment creation, all
 * starting at `enabled = false` with blank `client_id` /
 * `encrypted_client_secret`. The dashboard renders them as "configured
 * but disabled" until the operator fills in credentials.
 */
final class OauthProviderSeeder
{
    public function __construct(private readonly PresetRegistry $presets) {}

    public function seed(Environment $environment): void
    {
        foreach ($this->presets->all() as $preset) {
            $exists = OauthProvider::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $environment->id)
                ->where('provider_key', $preset->key())
                ->exists();
            if ($exists) {
                continue;
            }

            OauthProvider::query()->withoutGlobalScopes()->create([
                'environment_id' => $environment->id,
                'provider_kind' => OauthProvider::KIND_PRESET,
                'provider_key' => $preset->key(),
                'name' => $preset->name(),
                'enabled' => false,
                'allow_sign_in' => true,
                'allow_sign_up' => true,
                'block_email_subaddresses' => false,
                'client_id' => '',
                'encrypted_client_secret' => '',
                'scopes' => $preset->defaultScopes(),
                'attribute_mapping' => $preset->defaultAttributeMapping(),
                'additional_authorization_params' => $preset->additionalAuthorizationParams(),
                'authorization_endpoint' => $preset->authorizationEndpoint(),
                'token_endpoint' => $preset->tokenEndpoint(),
                'userinfo_endpoint' => $preset->userinfoEndpoint(),
                'jwks_uri' => $preset->jwksUri(),
                'id_token_signing_algs' => $preset->idTokenSigningAlgs(),
                'userinfo_method' => $preset->userinfoMethod(),
                'userinfo_auth' => $preset->userinfoAuth(),
            ]);
        }
    }
}
