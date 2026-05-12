<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OauthApplication;

/**
 * BAPI shape for `OauthApplication`. Mirrors OA-2's `OauthApplication`
 * schema (`additionalProperties: false`); the plaintext `client_secret`
 * is only ever surfaced via `OauthApplicationWithSecretResource` on
 * create + rotate-secret responses, never on read.
 */
final class OauthApplicationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(OauthApplication $app): array
    {
        return [
            'id' => $app->id,
            'object' => 'oauth_application',
            'name' => $app->name,
            'client_id' => $app->client_id,
            'callback_urls' => is_array($app->callback_urls) ? array_values($app->callback_urls) : [],
            'scopes' => is_array($app->scopes) ? array_values($app->scopes) : [],
            'is_public' => (bool) $app->is_public,
            'created_at' => $app->created_at?->getTimestampMs(),
            'updated_at' => $app->updated_at?->getTimestampMs(),
        ];
    }

    /**
     * Variant emitted on create + rotate-secret responses only; layers
     * the plaintext `client_secret` on top of the standard shape. Public
     * clients get no `client_secret` (PKCE-only), so the field is
     * intentionally absent for `is_public: true`.
     *
     * @return array<string, mixed>
     */
    public static function withSecret(OauthApplication $app, ?string $plaintextSecret): array
    {
        $base = self::from($app);
        if ($app->is_public || $plaintextSecret === null) {
            return $base;
        }
        $base['client_secret'] = $plaintextSecret;

        return $base;
    }
}
