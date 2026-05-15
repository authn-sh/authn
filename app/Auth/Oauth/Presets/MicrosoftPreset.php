<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

/**
 * Microsoft Entra "common" multi-tenant endpoints. Operators that want
 * to lock the integration to a single tenant override the
 * `additional_authorization_params.tenant` value via the BAPI patch
 * surface (AU-5) or the dashboard wizard (AU-13).
 */
final class MicrosoftPreset implements PresetContract
{
    public function key(): string
    {
        return 'microsoft';
    }

    public function name(): string
    {
        return 'Microsoft';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    }

    public function tokenEndpoint(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    }

    public function userinfoEndpoint(): string
    {
        return 'https://graph.microsoft.com/oidc/userinfo';
    }

    public function jwksUri(): ?string
    {
        return 'https://login.microsoftonline.com/common/discovery/v2.0/keys';
    }

    public function issuer(): ?string
    {
        // Microsoft's v2 issuer carries the tenant id. The "common"
        // endpoint accepts multiple tenants — we skip strict-issuer
        // validation since the operator's tenant choice would have to
        // be configurable, and the JWS signature + audience check are
        // already sufficient.
        return null;
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
            'offline_access',
            'User.Read',
            'User.ReadBasic.All',
            'Calendars.Read',
            'Files.Read',
            'Mail.Read',
            'Contacts.Read',
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
        return ['response_mode' => 'query'];
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
