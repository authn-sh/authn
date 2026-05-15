<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

/**
 * Discord OAuth2 (not OIDC). Claims come from `/users/@me`; there is no
 * id_token. The `provider_user_id` mapping pins our External Account row
 * to Discord's stable `id` snowflake.
 */
final class DiscordPreset implements PresetContract
{
    public function key(): string
    {
        return 'discord';
    }

    public function name(): string
    {
        return 'Discord';
    }

    public function authorizationEndpoint(): string
    {
        return 'https://discord.com/oauth2/authorize';
    }

    public function tokenEndpoint(): string
    {
        return 'https://discord.com/api/oauth2/token';
    }

    public function userinfoEndpoint(): string
    {
        return 'https://discord.com/api/users/@me';
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
        return ['identify', 'email'];
    }

    public function availableScopes(): array
    {
        return [
            'identify',
            'email',
            'guilds',
            'guilds.members.read',
            'connections',
            'gdm.join',
            'messages.read',
        ];
    }

    public function defaultAttributeMapping(): array
    {
        return [
            'provider_user_id' => 'id',
            'username' => 'username',
            'email_address' => 'email',
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
