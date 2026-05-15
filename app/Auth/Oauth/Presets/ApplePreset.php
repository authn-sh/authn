<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

/**
 * Apple's "Sign in with Apple" requires `response_mode=form_post` so the
 * IdP POSTs back to the redirect URI instead of a query-string redirect;
 * AU-6's callback honours both transport methods.
 */
final class ApplePreset implements PresetContract
{
    public function key(): string
    {
        return 'apple';
    }

    public function name(): string
    {
        return 'Apple';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://appleid.apple.com/auth/authorize';
    }

    public function tokenEndpoint(): string
    {
        return 'https://appleid.apple.com/auth/token';
    }

    public function userinfoEndpoint(): string
    {
        // Apple does not expose a separate userinfo endpoint — claims
        // come back in the id_token. AU-6's callback short-circuits the
        // userinfo round-trip when `userinfo_endpoint` matches the token
        // endpoint.
        return 'https://appleid.apple.com/auth/token';
    }

    public function jwksUri(): ?string
    {
        return 'https://appleid.apple.com/auth/keys';
    }

    public function issuer(): ?string
    {
        return 'https://appleid.apple.com';
    }

    public function defaultScopes(): array
    {
        return ['name', 'email'];
    }

    public function availableScopes(): array
    {
        return ['name', 'email'];
    }

    public function defaultAttributeMapping(): array
    {
        return [
            'email' => 'email',
            'email_verified' => 'email_verified',
            'first_name' => 'given_name',
            'last_name' => 'family_name',
        ];
    }

    public function additionalAuthorizationParams(): array
    {
        return ['response_mode' => 'form_post'];
    }

    public function idTokenSigningAlgs(): array
    {
        return ['ES256'];
    }

    public function userinfoMethod(): string
    {
        return 'POST';
    }

    public function userinfoAuth(): string
    {
        return 'bearer';
    }
}
