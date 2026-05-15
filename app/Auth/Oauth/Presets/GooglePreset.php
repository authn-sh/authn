<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

final class GooglePreset implements PresetContract
{
    public function key(): string
    {
        return 'google';
    }

    public function name(): string
    {
        return 'Google';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    public function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    public function userinfoEndpoint(): string
    {
        return 'https://openidconnect.googleapis.com/v1/userinfo';
    }

    public function jwksUri(): ?string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }

    public function issuer(): ?string
    {
        return 'https://accounts.google.com';
    }

    public function defaultScopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    public function availableScopes(): array
    {
        return [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/drive.readonly',
            'https://www.googleapis.com/auth/contacts.readonly',
            'https://www.googleapis.com/auth/gmail.readonly',
        ];
    }

    public function defaultAttributeMapping(): array
    {
        return [
            'email' => 'email',
            'email_verified' => 'email_verified',
            'first_name' => 'given_name',
            'last_name' => 'family_name',
            'image_url' => 'picture',
        ];
    }

    public function additionalAuthorizationParams(): array
    {
        return ['access_type' => 'online', 'prompt' => 'select_account'];
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
