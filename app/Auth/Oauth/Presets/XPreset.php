<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

/**
 * X (formerly Twitter) OAuth2 v2. X mandates PKCE on the authorization
 * code grant — when the engine gains PKCE plumbing the X preset will be
 * marked as requiring it; until then operators must configure their
 * X developer app for confidential PKCE flow on the X side.
 */
final class XPreset implements PresetContract
{
    public function key(): string
    {
        return 'x';
    }

    public function name(): string
    {
        return 'X';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://twitter.com/i/oauth2/authorize';
    }

    public function tokenEndpoint(): string
    {
        return 'https://api.twitter.com/2/oauth2/token';
    }

    public function userinfoEndpoint(): string
    {
        return 'https://api.twitter.com/2/users/me';
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
        return ['tweet.read', 'users.read'];
    }

    public function defaultAttributeMapping(): array
    {
        return [
            'provider_user_id' => 'id',
            'username' => 'username',
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
