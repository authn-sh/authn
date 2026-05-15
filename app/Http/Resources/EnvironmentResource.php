<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Environment;
use App\Models\OauthProvider;
use App\Settings\MultiFactorSettings;

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
        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];
        $sessions = is_array($appearance['sessions'] ?? null) ? $appearance['sessions'] : [];
        $localization = is_array($environment->localization) ? $environment->localization : [];
        $multiFactor = MultiFactorSettings::fromUserSettings($userSettings);

        $signUpMethods = is_array($userSettings['sign_up_methods'] ?? null) ? $userSettings['sign_up_methods'] : [];
        $phoneMethod = is_array($signUpMethods['phone'] ?? null) ? $signUpMethods['phone'] : [];
        $phoneEnabled = (bool) ($phoneMethod['enabled'] ?? false);
        $phoneRequired = (bool) ($phoneMethod['required'] ?? false);
        $phoneReq = match (true) {
            $phoneEnabled && $phoneRequired => 'required',
            $phoneEnabled => 'optional',
            default => 'off',
        };
        $firstFactors = ['password', 'email_code', 'reset_password_email_code', 'ticket'];
        if ($phoneEnabled) {
            $firstFactors[] = 'phone_code';
        }
        $oauthRows = OauthProvider::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $environment->id)
            ->where('enabled', true)
            ->orderBy('provider_key')
            ->get();
        foreach ($oauthRows as $row) {
            $firstFactors[] = 'oauth_'.$row->provider_key;
        }

        return [
            'object' => 'environment',
            'id' => $environment->id,

            'auth_config' => [
                'identifier_requirements' => [
                    'email_address' => 'required',
                    'phone_number' => $phoneReq,
                    'username' => 'off',
                ],
                'first_factors' => $firstFactors,
                'second_factors' => $multiFactor->enabledStrategies(),
                'sign_up_modes' => ['public'],
            ],

            'display_config' => [
                'application_name' => $appearance['application_name'] ?? config('app.name'),
                'branded' => (bool) ($appearance['branded'] ?? false),
                'support_email' => $appearance['support_email'] ?? null,
                'brand_color' => $appearance['brand_color'] ?? null,
                'logo_url' => $appearance['logo_url'] ?? null,
                'favicon_url' => $appearance['favicon_url'] ?? null,
                'redirects' => \App\Settings\RedirectsSettings::fromUserSettings($userSettings)->toArray(),
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
                'provider' => $appearance['captcha']['provider'] ?? 'none',
                'widget_type' => $appearance['captcha']['widget_type'] ?? 'invisible',
                'public_key' => $appearance['captcha']['public_key'] ?? null,
            ],

            'localization' => [
                'default_locale' => $localization['default_locale'] ?? 'en-US',
                'fallback_locale' => $localization['fallback_locale'] ?? 'en-US',
                'supported_locales' => $localization['supported_locales'] ?? ['en-US'],
                'override_etag' => $environment->localization_override_etag,
            ],

            'oauth_providers' => $oauthRows->map(fn (OauthProvider $p): array => [
                'provider_key' => $p->provider_key,
                'name' => $p->name,
                'logo_url' => is_array($p->additional_authorization_params)
                    ? ($p->additional_authorization_params['logo_url'] ?? null)
                    : null,
                'strategy' => 'oauth_'.$p->provider_key,
            ])->all(),

            'paths' => $appearance['paths'] ?? [],

            'appearance' => [
                'variables' => (object) (is_array($appearance['variables'] ?? null) ? $appearance['variables'] : []),
                'elements' => (object) (is_array($appearance['elements'] ?? null) ? $appearance['elements'] : []),
                'layout' => (object) (is_array($appearance['layout'] ?? null) ? $appearance['layout'] : []),
                'etag' => $environment->appearance_etag,
            ],

            'sessions' => [
                'session_token_lifetime_seconds' => $sessions['lifetime_seconds'] ?? 60,
                'multi_session' => (bool) ($sessions['multi_session'] ?? true),
            ],

            'created_at' => $environment->created_at?->getTimestampMs(),
            'updated_at' => $environment->updated_at?->getTimestampMs(),
        ];
    }
}
