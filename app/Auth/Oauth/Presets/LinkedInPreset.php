<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

/**
 * LinkedIn "Sign In with LinkedIn using OpenID Connect". Endpoints
 * mirror the values discovery at
 * `https://www.linkedin.com/oauth/.well-known/openid-configuration`
 * exposes; discovery isn't run here because preset rows ship with
 * the endpoints baked in.
 */
final class LinkedInPreset implements PresetContract
{
    public function key(): string
    {
        return 'linkedin';
    }

    public function name(): string
    {
        return 'LinkedIn';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization';
    }

    public function tokenEndpoint(): string
    {
        return 'https://www.linkedin.com/oauth/v2/accessToken';
    }

    public function userinfoEndpoint(): string
    {
        return 'https://api.linkedin.com/v2/userinfo';
    }

    public function jwksUri(): ?string
    {
        return 'https://www.linkedin.com/oauth/openid/jwks';
    }

    public function issuer(): ?string
    {
        return 'https://www.linkedin.com/oauth';
    }

    public function defaultScopes(): array
    {
        return ['openid', 'profile', 'email'];
    }

    public function defaultAttributeMapping(): array
    {
        return [
            'provider_user_id' => 'sub',
            'email' => 'email',
            'email_verified' => 'email_verified',
            'first_name' => 'given_name',
            'last_name' => 'family_name',
            'image_url' => 'picture',
        ];
    }

    public function additionalAuthorizationParams(): array
    {
        return [];
    }

    public function idTokenSigningAlgs(): array
    {
        return ['RS256'];
    }

    public function userinfoMethod(): string
    {
        return 'GET';
    }

    public function userinfoAuth(): string
    {
        return 'bearer';
    }
}
