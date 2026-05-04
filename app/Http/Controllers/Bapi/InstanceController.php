<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Models\Environment;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * BAPI instance settings surface.
 *
 *   GET    /v1/instance                     full env settings blob
 *   PATCH  /v1/instance                     top-level toggles
 *   PATCH  /v1/instance/restrictions        restrictions sub-object
 *   PATCH  /v1/instance/organization_settings  v0.1 placeholder
 *
 * The full attribute matrix shape (PLAN §13.2) is exposed read-only via
 * the FAPI `/v1/environment` endpoint; PATCH here writes through to the
 * `Environment` JSON blobs that the FAPI then reads.
 */
final class InstanceController
{
    public function show(): JsonResponse
    {
        $env = app(Environment::class);
        $appearance = is_array($env->appearance) ? $env->appearance : [];
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $restrictions = is_array($userSettings['restrictions'] ?? null) ? $userSettings['restrictions'] : [];
        $sessions = is_array($userSettings['sessions'] ?? null) ? $userSettings['sessions'] : [];
        $signingKeys = is_array($userSettings['signing_keys'] ?? null) ? $userSettings['signing_keys'] : [];
        $attack = is_array($userSettings['attack_protection'] ?? null) ? $userSettings['attack_protection'] : [];
        $captcha = is_array($appearance['captcha'] ?? null) ? $appearance['captcha'] : [];
        $paths = is_array($appearance['paths'] ?? null) ? $appearance['paths'] : [];

        return response()->json([
            'object' => 'instance_settings',
            'support_email' => $appearance['support_email'] ?? null,
            'enhanced_email_deliverability' => false,
            'sign_up_modes' => [$env->signup_mode],
            'attribute_settings' => $this->attributeSettings($userSettings),
            'restrictions' => [
                'allowlist_enabled' => (bool) ($restrictions['allowlist_enabled'] ?? $env->signup_mode === Environment::SIGNUP_MODE_RESTRICTED),
                'blocklist_enabled' => (bool) ($restrictions['blocklist_enabled'] ?? true),
                'block_email_subaddresses' => (bool) ($restrictions['block_email_subaddresses'] ?? $userSettings['block_email_subaddresses'] ?? false),
                'block_disposable_email_domains' => (bool) ($restrictions['block_disposable_email_domains'] ?? $userSettings['block_disposable_email_domains'] ?? false),
                'ignore_dots_for_gmail_addresses' => (bool) ($restrictions['ignore_dots_for_gmail_addresses'] ?? $userSettings['ignore_dots_for_gmail_addresses'] ?? true),
            ],
            'attack_protection' => [
                'captcha' => [
                    'provider' => $captcha['provider'] ?? 'none',
                    'widget_type' => $captcha['widget_type'] ?? 'smart',
                    'public_key' => $captcha['public_key'] ?? null,
                ],
                'brute_force' => [
                    'enabled' => (bool) (($attack['brute_force']['enabled'] ?? null) ?? true),
                    'max_attempts' => (int) (($attack['brute_force']['max_attempts'] ?? null) ?? 100),
                    'lockout_duration_seconds' => (int) (($attack['brute_force']['lockout_duration_seconds'] ?? null) ?? 3600),
                ],
            ],
            'sessions' => [
                'lifetime_seconds' => (int) ($sessions['lifetime_seconds'] ?? 604800),
                'inactivity_timeout_seconds' => $sessions['inactivity_timeout_seconds'] ?? null,
                'multi_session' => (bool) ($sessions['multi_session'] ?? true),
                'max_concurrent_sessions_per_client' => (int) ($sessions['max_concurrent_sessions_per_client'] ?? 10),
                'url_based_session_syncing' => (bool) ($sessions['url_based_session_syncing'] ?? false),
                'session_token_template' => $sessions['session_token_template'] ?? null,
            ],
            'paths' => [
                'sign_in_url' => $paths['sign_in_url'] ?? rtrim((string) ($appearance['home_url'] ?? 'https://example.com'), '/').'/sign-in',
                'sign_up_url' => $paths['sign_up_url'] ?? rtrim((string) ($appearance['home_url'] ?? 'https://example.com'), '/').'/sign-up',
                'after_sign_in_url' => $paths['after_sign_in_url'] ?? rtrim((string) ($appearance['home_url'] ?? 'https://example.com'), '/').'/',
                'after_sign_up_url' => $paths['after_sign_up_url'] ?? rtrim((string) ($appearance['home_url'] ?? 'https://example.com'), '/').'/',
                'unauthorized_sign_in_url' => $paths['unauthorized_sign_in_url'] ?? rtrim((string) ($appearance['home_url'] ?? 'https://example.com'), '/').'/sign-in',
                'user_profile_url' => $paths['user_profile_url'] ?? rtrim((string) ($appearance['home_url'] ?? 'https://example.com'), '/').'/account',
            ],
            'test_mode' => $userSettings['test_mode'] ?? 'disabled',
            'signing_keys' => [
                'rotation_cadence_days' => (int) ($signingKeys['rotation_cadence_days'] ?? 90),
                'pre_publish_window_seconds' => (int) ($signingKeys['pre_publish_window_seconds'] ?? 86400),
                'retire_window_seconds' => (int) ($signingKeys['retire_window_seconds'] ?? 604800),
            ],
            'organizations' => [
                'enabled' => false,
            ],
            'audit_log_retention_days' => (int) ($userSettings['audit_log_retention_days'] ?? 90),
        ]);
    }

    /**
     * @param  array<string, mixed>  $userSettings
     * @return array<string, array<string, mixed>>
     */
    private function attributeSettings(array $userSettings): array
    {
        $defaults = [
            'email_address' => ['enabled' => true, 'required' => true, 'used_for_first_factor' => true, 'used_for_second_factor' => false, 'verifications' => ['email_code'], 'verify_at_sign_up' => true],
            'phone_number' => ['enabled' => false, 'required' => false, 'used_for_first_factor' => false, 'used_for_second_factor' => false, 'verifications' => [], 'verify_at_sign_up' => false],
            'username' => ['enabled' => false, 'required' => false, 'used_for_first_factor' => false, 'used_for_second_factor' => false, 'verifications' => [], 'verify_at_sign_up' => false],
            'first_name' => ['enabled' => true, 'required' => false, 'used_for_first_factor' => false, 'used_for_second_factor' => false, 'verifications' => [], 'verify_at_sign_up' => false],
            'last_name' => ['enabled' => true, 'required' => false, 'used_for_first_factor' => false, 'used_for_second_factor' => false, 'verifications' => [], 'verify_at_sign_up' => false],
            'password' => ['enabled' => true, 'required' => true, 'used_for_first_factor' => true, 'used_for_second_factor' => false, 'verifications' => [], 'verify_at_sign_up' => false],
        ];

        $overrides = is_array($userSettings['attributes'] ?? null) ? $userSettings['attributes'] : [];
        foreach ($overrides as $name => $cfg) {
            if (! isset($defaults[$name]) || ! is_array($cfg)) {
                continue;
            }
            $defaults[$name] = array_merge($defaults[$name], $cfg);
        }

        return $defaults;
    }

    public function update(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $appearance = is_array($env->appearance) ? $env->appearance : [];
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $previousTestMode = $userSettings['test_mode'] ?? null;

        if ($request->has('support_email')) {
            $appearance['support_email'] = $request->input('support_email');
        }
        if ($request->has('test_mode') && in_array($request->input('test_mode'), ['enabled', 'disabled', 'rejected'], true)) {
            $userSettings['test_mode'] = $request->input('test_mode');
        }
        if ($request->has('appearance') && is_array($request->input('appearance'))) {
            $appearance = array_merge($appearance, $request->input('appearance'));
        }
        if ($request->has('user_settings') && is_array($request->input('user_settings'))) {
            $userSettings = array_replace_recursive($userSettings, $request->input('user_settings'));
        }
        $env->forceFill([
            'appearance' => $appearance,
            'user_settings' => $userSettings,
        ])->save();

        // Audit + warn when an operator flips test_mode to enabled on a
        // production env. PLAN §9.11: emit system.testmode_enabled_in_production
        // and surface a persistent banner in the dashboard.
        $newTestMode = $userSettings['test_mode'] ?? null;
        if ($env->kind === Environment::KIND_PRODUCTION
            && $previousTestMode !== Environment::TEST_MODE_ENABLED
            && $newTestMode === Environment::TEST_MODE_ENABLED
        ) {
            Log::warning('system.testmode_enabled_in_production', [
                'environment_id' => $env->id,
            ]);
            app(Emitter::class)->emit(
                'system.testmode_enabled_in_production',
                ['environment_id' => $env->id, 'severity' => 'warning'],
                $env,
            );
        }

        return $this->show();
    }

    public function updateRestrictions(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $r = is_array($userSettings['restrictions'] ?? null) ? $userSettings['restrictions'] : [];
        foreach ([
            'allowlist_enabled', 'blocklist_enabled',
            'block_email_subaddresses', 'block_disposable_email_domains', 'ignore_dots_for_gmail_addresses',
        ] as $field) {
            if ($request->has($field)) {
                $r[$field] = $request->boolean($field);
            }
        }
        $userSettings['restrictions'] = $r;

        // Mirror allowlist_enabled into the canonical signup_mode column so
        // the FAPI sign-up flow doesn't need to inspect two places.
        if ($request->has('allowlist_enabled')) {
            $env->signup_mode = $request->boolean('allowlist_enabled')
                ? Environment::SIGNUP_MODE_RESTRICTED
                : Environment::SIGNUP_MODE_PUBLIC;
        }

        $env->user_settings = $userSettings;
        $env->save();

        return $this->show();
    }

    public function updateOrganizationSettings(Request $request): JsonResponse
    {
        // v0.1 placeholder — the org settings schema lights up in v0.2. We
        // accept the body shape without write-through so SDK callers can
        // exercise the endpoint today.
        return response()->json([
            'object' => 'organization_settings',
            'enabled' => false,
            'note' => 'organization_settings is a v0.1 placeholder; the full schema lands in v0.2.',
        ]);
    }
}
