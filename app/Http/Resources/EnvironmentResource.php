<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Environment;

/**
 * The public-facing shape returned by `GET /v1/environment`. The SDK
 * loads this once at boot to learn which strategies are enabled, what
 * branding to apply, where to redirect on success, etc.
 *
 * Strict about what it exposes: secret keys (captcha.secret_key,
 * private signing material) never leak. The full surface (org settings,
 * commerce settings, oauth_providers, full localization catalog) lights
 * up across v0.2 → v0.7 — v0.1 returns stubs for the categories that
 * aren't wired yet so the SDK shape is stable from day one.
 */
final class EnvironmentResource
{
    public static function from(Environment $environment): array
    {
        $appearance = is_array($environment->appearance) ? $environment->appearance : [];
        $sessions = is_array($appearance['sessions'] ?? null) ? $appearance['sessions'] : [];
        $localization = is_array($environment->localization) ? $environment->localization : [];

        return [
            'object' => 'environment',
            'id' => $environment->id,

            'auth_config' => [
                // v0.1 supports identifier-based sign-in via email only.
                'identifiers' => ['email_address'],
                'first_factors' => ['password', 'email_code', 'reset_password_email_code', 'ticket'],
                'second_factors' => [],
                'sign_up_modes' => ['public'],
            ],

            'display_config' => [
                'application_name' => $appearance['application_name'] ?? config('app.name'),
                'branded' => (bool) ($appearance['branded'] ?? false),
                'support_email' => $appearance['support_email'] ?? null,
                'brand_color' => $appearance['brand_color'] ?? null,
                'logo_url' => $appearance['logo_url'] ?? null,
                'favicon_url' => $appearance['favicon_url'] ?? null,
            ],

            'user_settings' => [
                // Per-attribute settings (full PLAN §13.2 shape) lands in AU-13;
                // v0.1 returns the implicit default: email + password required,
                // no other identifiers.
                'attributes' => [
                    'email_address' => [
                        'enabled' => true,
                        'required' => true,
                        'used_for_first_factor' => true,
                        'used_for_second_factor' => false,
                        'verifications' => ['email_code'],
                        'verify_at_sign_up' => true,
                    ],
                    'password' => [
                        'enabled' => true,
                        'required' => true,
                        'used_for_first_factor' => true,
                        'used_for_second_factor' => false,
                        'verifications' => [],
                        'verify_at_sign_up' => false,
                    ],
                    'first_name' => [
                        'enabled' => true,
                        'required' => false,
                        'used_for_first_factor' => false,
                        'used_for_second_factor' => false,
                        'verifications' => [],
                        'verify_at_sign_up' => false,
                    ],
                    'last_name' => [
                        'enabled' => true,
                        'required' => false,
                        'used_for_first_factor' => false,
                        'used_for_second_factor' => false,
                        'verifications' => [],
                        'verify_at_sign_up' => false,
                    ],
                ],
            ],

            // v0.2 lights this up.
            'organization_settings' => [
                'enabled' => false,
            ],

            // Out of scope for v0.1 — billing entities are deferred.
            'commerce_settings' => [
                'enabled' => false,
            ],

            // Bot protection (AU-18 wires the actual provider call). Public
            // half only — secret_key never leaves the server.
            'captcha' => [
                'provider' => $appearance['captcha']['provider'] ?? null,
                'widget_type' => $appearance['captcha']['widget_type'] ?? null,
                'public_key' => $appearance['captcha']['public_key'] ?? null,
            ],

            'localization' => [
                'default_locale' => $localization['default_locale'] ?? 'en-US',
                'supported_locales' => $localization['supported_locales'] ?? ['en-US'],
                'fallback_locale' => $localization['fallback_locale'] ?? 'en-US',
                // override_etag is bumped whenever overrides change so the SDK
                // can cache the catalog endpoint per-version. Implementation
                // lands when the localization editor ships.
                'override_etag' => $localization['override_etag'] ?? null,
            ],

            // v0.4 lights this up with preset + custom OIDC/OAuth2 providers.
            'oauth_providers' => [],

            'paths' => $appearance['paths'] ?? [],

            'sessions' => [
                // The bare minimum the SDK needs at boot to know its refresh
                // cadence. Full per-env config (multi_session, inactivity
                // timeout, …) lands in AU-13.
                'session_token_lifetime_seconds' => $sessions['lifetime_seconds'] ?? 60,
                'multi_session' => (bool) ($sessions['multi_session'] ?? true),
            ],

            'created_at' => $environment->created_at?->getTimestampMs(),
            'updated_at' => $environment->updated_at?->getTimestampMs(),
        ];
    }
}
