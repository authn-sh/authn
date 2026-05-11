<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

/**
 * Facebook Login (Graph API v18.0). Operator picks `public_profile` +
 * `email`; `email` requires the user to have a confirmed email on
 * their Facebook account.
 */
final class FacebookPreset implements PresetContract
{
    public function key(): string
    {
        return 'facebook';
    }

    public function name(): string
    {
        return 'Facebook';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://www.facebook.com/v18.0/dialog/oauth';
    }

    public function tokenEndpoint(): string
    {
        return 'https://graph.facebook.com/v18.0/oauth/access_token';
    }

    public function userinfoEndpoint(): string
    {
        return 'https://graph.facebook.com/v18.0/me';
    }

    public function jwksUri(): ?string
    {
        return null;
    }

    public function issuer(): ?string
    {
        return null;
    }

    public function defaultScopes(): array
    {
        return ['public_profile', 'email'];
    }

    public function defaultAttributeMapping(): array
    {
        return [
            'provider_user_id' => 'id',
            'email_address' => 'email',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
        ];
    }

    public function additionalAuthorizationParams(): array
    {
        // Graph `/me` returns only id + name by default. Operators that
        // need email + first/last must request the fields explicitly on
        // the userinfo call; the v0.4 callback will append `?fields=...`
        // when configured.
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
