<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

/**
 * Sign in with Slack — Slack's OIDC product (not the legacy v2 OAuth
 * scopes). The standard OIDC claim set is sufficient for our shape.
 */
final class SlackPreset implements PresetContract
{
    public function key(): string
    {
        return 'slack';
    }

    public function name(): string
    {
        return 'Slack';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://slack.com/openid/connect/authorize';
    }

    public function tokenEndpoint(): string
    {
        return 'https://slack.com/api/openid.connect.token';
    }

    public function userinfoEndpoint(): string
    {
        return 'https://slack.com/api/openid.connect.userInfo';
    }

    public function jwksUri(): ?string
    {
        return 'https://slack.com/openid/connect/keys';
    }

    public function issuer(): ?string
    {
        return 'https://slack.com';
    }

    public function defaultScopes(): array
    {
        return ['openid', 'profile', 'email'];
    }

    public function availableScopes(): array
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
