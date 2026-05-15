<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

/**
 * GitLab.com OIDC. Self-hosted GitLab instances should be configured as
 * `custom_oidc` rows pointed at the operator's instance issuer; this
 * preset specifically targets gitlab.com.
 */
final class GitLabPreset implements PresetContract
{
    public function key(): string
    {
        return 'gitlab';
    }

    public function name(): string
    {
        return 'GitLab';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://gitlab.com/oauth/authorize';
    }

    public function tokenEndpoint(): string
    {
        return 'https://gitlab.com/oauth/token';
    }

    public function userinfoEndpoint(): string
    {
        return 'https://gitlab.com/oauth/userinfo';
    }

    public function jwksUri(): ?string
    {
        return 'https://gitlab.com/oauth/discovery/keys';
    }

    public function issuer(): ?string
    {
        return 'https://gitlab.com';
    }

    public function defaultScopes(): array
    {
        return ['openid', 'profile', 'email'];
    }

    public function availableScopes(): array
    {
        return [
            'openid',
            'profile',
            'email',
            'read_user',
            'read_api',
            'read_repository',
            'api',
        ];
    }

    public function defaultAttributeMapping(): array
    {
        return [
            'provider_user_id' => 'sub',
            'email' => 'email',
            'email_verified' => 'email_verified',
            'first_name' => 'given_name',
            'last_name' => 'family_name',
            'username' => 'preferred_username',
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
