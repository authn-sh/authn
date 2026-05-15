<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Models\Environment;
use App\Settings\MultiFactorSettings;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * BAPI instance settings surface.
 *
 *   GET    /v1/instance                     full env settings blob
 *   PATCH  /v1/instance                     top-level toggles
 *   PATCH  /v1/instance/restrictions        restrictions sub-object
 *   PATCH  /v1/instance/organization-settings  v0.1 placeholder
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
            'multi_factor' => MultiFactorSettings::fromUserSettings($userSettings)->toArray(),
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

        // Phone is now stored under sign_up_methods.phone.{enabled, required};
        // inflate it into the attribute-row shape so the BAPI response keeps
        // returning the per-attribute matrix consumers expect.
        $signUpMethods = is_array($userSettings['sign_up_methods'] ?? null) ? $userSettings['sign_up_methods'] : [];
        $phoneMethod = is_array($signUpMethods['phone'] ?? null) ? $signUpMethods['phone'] : [];
        $phoneEnabled = (bool) ($phoneMethod['enabled'] ?? false);
        $phoneRequired = (bool) ($phoneMethod['required'] ?? false);
        if ($phoneEnabled) {
            $defaults['phone_number']['enabled'] = true;
            $defaults['phone_number']['required'] = $phoneRequired;
            $defaults['phone_number']['used_for_first_factor'] = true;
            $defaults['phone_number']['verifications'] = ['phone_code'];
            $defaults['phone_number']['verify_at_sign_up'] = true;
        }

        return $defaults;
    }

    public function update(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $appearance = is_array($env->appearance) ? $env->appearance : [];
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $previousTestMode = $userSettings['test_mode'] ?? null;
        $previousMultiFactor = MultiFactorSettings::fromUserSettings($userSettings);
        $previousSignUpMethods = is_array($userSettings['sign_up_methods'] ?? null) ? $userSettings['sign_up_methods'] : [];

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

        $signUpMethodsChanged = false;
        if ($request->has('sign_up_methods')) {
            $patch = $request->input('sign_up_methods');
            if (! is_array($patch)) {
                throw ValidationException::withMessages(['sign_up_methods' => 'sign_up_methods must be an object.']);
            }
            Validator::make($patch, [
                'phone' => 'sometimes|array',
                'phone.enabled' => 'sometimes|boolean',
                'phone.required' => 'sometimes|boolean',
            ])->validate();

            $next = $previousSignUpMethods;
            if (isset($patch['phone']) && is_array($patch['phone'])) {
                $next['phone'] = array_replace(
                    is_array($next['phone'] ?? null) ? $next['phone'] : [],
                    $patch['phone'],
                );
            }
            $userSettings['sign_up_methods'] = $next;
            $signUpMethodsChanged = $next != $previousSignUpMethods;
        }

        $multiFactorChanged = false;
        if ($request->has('multi_factor')) {
            $patch = $request->input('multi_factor');
            if (! is_array($patch)) {
                throw ValidationException::withMessages(['multi_factor' => 'multi_factor must be an object.']);
            }
            Validator::make($patch, [
                'totp' => 'sometimes|array',
                'totp.enabled' => 'sometimes|boolean',
                'backup_codes' => 'sometimes|array',
                'backup_codes.enabled' => 'sometimes|boolean',
                'backup_codes.default_count' => [
                    'sometimes',
                    'integer',
                    'between:'.MultiFactorSettings::MIN_BACKUP_CODE_COUNT.','.MultiFactorSettings::MAX_BACKUP_CODE_COUNT,
                ],
                'phone_code' => 'sometimes|array',
                'phone_code.enabled' => 'sometimes|boolean',
            ])->validate();

            $next = $previousMultiFactor->withPatch($patch);
            $userSettings['multi_factor'] = $next->toArray();
            $multiFactorChanged = $next != $previousMultiFactor;
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

        if ($multiFactorChanged) {
            $next = MultiFactorSettings::fromUserSettings($userSettings);
            Log::info('instance.config.multi_factor_updated', [
                'environment_id' => $env->id,
                'before' => $previousMultiFactor->toArray(),
                'after' => $next->toArray(),
            ]);
            app(Emitter::class)->emit(
                'instance.config.multi_factor_updated',
                [
                    'environment_id' => $env->id,
                    'before' => $previousMultiFactor->toArray(),
                    'after' => $next->toArray(),
                ],
                $env,
            );
        }

        if ($signUpMethodsChanged) {
            $nextSignUpMethods = is_array($userSettings['sign_up_methods'] ?? null) ? $userSettings['sign_up_methods'] : [];
            Log::info('instance.config.sign_up_methods_updated', [
                'environment_id' => $env->id,
                'before' => $previousSignUpMethods,
                'after' => $nextSignUpMethods,
            ]);
            app(Emitter::class)->emit(
                'instance.config.sign_up_methods_updated',
                [
                    'environment_id' => $env->id,
                    'before' => $previousSignUpMethods,
                    'after' => $nextSignUpMethods,
                ],
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
