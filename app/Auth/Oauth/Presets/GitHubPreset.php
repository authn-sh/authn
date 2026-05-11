<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

final class GitHubPreset implements PresetContract
{
    public function key(): string
    {
        return 'github';
    }

    public function name(): string
    {
        return 'GitHub';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://github.com/login/oauth/authorize';
    }

    public function tokenEndpoint(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    public function userinfoEndpoint(): string
    {
        return 'https://api.github.com/user';
    }

    public function jwksUri(): ?string
    {
        return null;
    }

    public function issuer(): ?string
    {
        // GitHub doesn't issue an id_token — pure OAuth2, not OIDC.
        return null;
    }

    public function defaultScopes(): array
    {
        return ['read:user', 'user:email'];
    }

    public function defaultAttributeMapping(): array
    {
        return [
            'email' => 'email',
            'first_name' => 'name',
            'image_url' => 'avatar_url',
            'username' => 'login',
        ];
    }

    public function additionalAuthorizationParams(): array
    {
        return [];
    }

    public function idTokenSigningAlgs(): array
    {
        return [];
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
