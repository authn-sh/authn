<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\Oauth\PresetRegistry;
use App\Models\Environment;
use App\Models\OauthProvider;
use InvalidArgumentException;

/**
 * Test-only helper that replaces the deleted `OauthProviderSeeder`.
 * Each call site now explicitly persists the preset-shaped row(s) it
 * needs instead of relying on the per-env seeder to plant 10 disabled
 * rows.
 */
final class OauthProviderFixtures
{
    /** Persist a preset-shaped row (blank credentials, disabled) for the given key. */
    public static function blankPreset(Environment $env, string $key): OauthProvider
    {
        $preset = app(PresetRegistry::class)->get($key);
        if ($preset === null) {
            throw new InvalidArgumentException("Unknown preset: {$key}");
        }

        return OauthProvider::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
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

    /** Persist a fully-configured preset row (enabled, with credentials). */
    public static function configuredPreset(Environment $env, string $key, array $overrides = []): OauthProvider
    {
        $row = self::blankPreset($env, $key);
        $row->forceFill(array_merge([
            'enabled' => true,
            'client_id' => 'test-client-id',
            'encrypted_client_secret' => 'test-encrypted-secret',
        ], $overrides))->save();

        return $row->fresh();
    }
}
