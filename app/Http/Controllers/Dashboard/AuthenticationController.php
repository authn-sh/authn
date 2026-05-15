<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Auth\Oauth\PresetRegistry;
use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Localization\CanonicalSchema;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\JwtTemplate;
use App\Models\OauthProvider;
use App\Settings\MultiFactorSettings;
use App\Settings\PasskeySettings;
use App\Settings\SignInMethodsSettings;
use App\Settings\SignUpMethodsSettings;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class AuthenticationController
{
    use ResolvesDashboardEnv;

    private const AUTH_SECTIONS = ['sign-in', 'sign-up', 'mfa', 'providers', 'enterprise-sso', 'jwt-templates'];

    private const BRANDING_SECTIONS = ['appearance', 'localization'];

    public function __construct(
        private readonly PresetRegistry $oauthPresets,
    ) {}

    public function authentication(string $project_slug, string $env_slug, ?string $section = null): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $section = in_array($section, self::AUTH_SECTIONS, true) ? $section : 'sign-in';
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];

        return match ($section) {
            'sign-in' => Inertia::render('Dashboard/Configure/Authentication/SignIn', [
                'sign_in_methods' => SignInMethodsSettings::fromUserSettings($userSettings)->toArray(),
            ]),
            'sign-up' => Inertia::render('Dashboard/Configure/Authentication/SignUp', [
                'sign_up_methods' => SignUpMethodsSettings::fromUserSettings($userSettings)->toArray(),
            ]),
            'mfa' => Inertia::render('Dashboard/Configure/Authentication/Mfa', [
                'multi_factor' => MultiFactorSettings::fromUserSettings($userSettings)->toArray(),
                'passkey_enabled' => PasskeySettings::fromUserSettings($userSettings)->enabled,
            ]),
            'providers' => Inertia::render('Dashboard/Configure/Authentication/Providers', [
                'oauth_providers' => OauthProvider::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->orderBy('provider_kind')
                    ->orderBy('provider_key')
                    ->get()
                    ->map(fn (OauthProvider $p) => $this->oauthRowShape($p))->all(),
                'oauth_preset_keys' => $this->oauthPresets->keys(),
            ]),
            'enterprise-sso' => Inertia::render('Dashboard/Configure/Authentication/EnterpriseSso', [
                'enterprise_connections' => EnterpriseConnection::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->orderBy('organization_id')
                    ->orderBy('protocol')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (EnterpriseConnection $c) => $this->enterpriseConnectionRowShape($c))->all(),
            ]),
            'jwt-templates' => Inertia::render('Dashboard/Configure/Authentication/JwtTemplates', [
                'jwt_templates' => JwtTemplate::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->whereNull('removed_at')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (JwtTemplate $t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'claims' => is_array($t->claims) ? $t->claims : [],
                        'lifetime' => $t->lifetime,
                        'allowed_clock_skew' => $t->allowed_clock_skew,
                        'signing_algorithm' => $t->signing_algorithm,
                        'has_custom_signing_key' => $t->custom_signing_key !== null && $t->custom_signing_key !== '',
                        'last_used_at' => $t->last_used_at?->getTimestampMs(),
                        'created_at' => $t->created_at?->getTimestampMs(),
                    ])->all(),
            ]),
        };
    }

    public function branding(string $project_slug, string $env_slug, ?string $section = null): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $section = in_array($section, self::BRANDING_SECTIONS, true) ? $section : 'appearance';

        return match ($section) {
            'appearance' => Inertia::render('Dashboard/Configure/Branding/Appearance', [
                'appearance' => is_array($env->appearance) ? $env->appearance : [],
            ]),
            'localization' => Inertia::render('Dashboard/Configure/Branding/Localization', [
                'localization' => $this->localizationShape($env),
                'localization_canonical' => [
                    'shipped_locales' => CanonicalSchema::SHIPPED_LOCALES,
                    'fallback_locale' => CanonicalSchema::FALLBACK_LOCALE,
                    'keys' => CanonicalSchema::keys(),
                    'en_us_catalog' => CanonicalSchema::catalog('en-US'),
                ],
            ]),
        };
    }

    public function updateMultiFactor(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'totp.enabled' => ['required', 'boolean'],
            'backup_codes.enabled' => ['required', 'boolean'],
            'backup_codes.default_count' => [
                'required', 'integer',
                'between:'.MultiFactorSettings::MIN_BACKUP_CODE_COUNT.','.MultiFactorSettings::MAX_BACKUP_CODE_COUNT,
            ],
            'phone_code.enabled' => ['required', 'boolean'],
        ]);

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $previous = MultiFactorSettings::fromUserSettings($userSettings);
        $next = $previous->withPatch([
            'totp' => ['enabled' => $request->boolean('totp.enabled')],
            'backup_codes' => [
                'enabled' => $request->boolean('backup_codes.enabled'),
                'default_count' => (int) $request->input('backup_codes.default_count'),
            ],
            'phone_code' => ['enabled' => $request->boolean('phone_code.enabled')],
        ]);
        $userSettings['multi_factor'] = $next->toArray();
        $env->forceFill(['user_settings' => $userSettings])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/multi-factor")
            ->with('multi_factor_saved', true);
    }

    public function updateSignUpMethods(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $request->validate([
            'email.enabled' => ['sometimes', 'boolean'],
            'email.required' => ['sometimes', 'boolean'],
            'email.verify' => ['sometimes', 'boolean'],
            'email.verify_code' => ['sometimes', 'boolean'],
            'email.restrict_changes' => ['sometimes', 'boolean'],
            'phone.enabled' => ['sometimes', 'boolean'],
            'phone.required' => ['sometimes', 'boolean'],
            'phone.verify' => ['sometimes', 'boolean'],
            'phone.restrict_changes' => ['sometimes', 'boolean'],
            'username.enabled' => ['sometimes', 'boolean'],
            'username.required' => ['sometimes', 'boolean'],
            'username.restrict_changes' => ['sometimes', 'boolean'],
            'password.enabled' => ['sometimes', 'boolean'],
            'password.signup_with_password' => ['sometimes', 'boolean'],
            'password.add_password' => ['sometimes', 'boolean'],
        ]);

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $previous = SignUpMethodsSettings::fromUserSettings($userSettings);
        $patch = [];
        foreach ([
            'email' => ['enabled', 'required', 'verify', 'verify_code', 'restrict_changes'],
            'phone' => ['enabled', 'required', 'verify', 'restrict_changes'],
            'username' => ['enabled', 'required', 'restrict_changes'],
            'password' => ['enabled', 'signup_with_password', 'add_password'],
        ] as $section => $keys) {
            $sectionPatch = [];
            foreach ($keys as $key) {
                if ($request->has("{$section}.{$key}")) {
                    $sectionPatch[$key] = $request->boolean("{$section}.{$key}");
                }
            }
            if ($sectionPatch !== []) {
                $patch[$section] = $sectionPatch;
            }
        }
        $next = $previous->withPatch($patch);
        $signUpMethods = is_array($userSettings['sign_up_methods'] ?? null) ? $userSettings['sign_up_methods'] : [];
        $userSettings['sign_up_methods'] = array_replace_recursive($signUpMethods, $next->toArray());
        $env->forceFill(['user_settings' => $userSettings])->save();

        return back(303)->with('sign_up_methods_saved', true);
    }

    public function updateSignInMethods(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $request->validate([
            'email.enabled' => ['sometimes', 'boolean'],
            'email.code' => ['sometimes', 'boolean'],
            'phone.enabled' => ['sometimes', 'boolean'],
            'username.enabled' => ['sometimes', 'boolean'],
        ]);

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $previous = SignInMethodsSettings::fromUserSettings($userSettings);
        $patch = [];
        foreach ([
            'email' => ['enabled', 'code'],
            'phone' => ['enabled'],
            'username' => ['enabled'],
        ] as $section => $keys) {
            $sectionPatch = [];
            foreach ($keys as $key) {
                if ($request->has("{$section}.{$key}")) {
                    $sectionPatch[$key] = $request->boolean("{$section}.{$key}");
                }
            }
            if ($sectionPatch !== []) {
                $patch[$section] = $sectionPatch;
            }
        }
        $next = $previous->withPatch($patch);
        $signInMethods = is_array($userSettings['sign_in_methods'] ?? null) ? $userSettings['sign_in_methods'] : [];
        $userSettings['sign_in_methods'] = array_replace_recursive($signInMethods, $next->toArray());
        $env->forceFill(['user_settings' => $userSettings])->save();

        return back(303)->with('sign_in_methods_saved', true);
    }

    public function updatePasskeyEnabled(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $request->validate(['enabled' => ['required', 'boolean']]);

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $strategies = is_array($userSettings['authentication_strategies'] ?? null)
            ? $userSettings['authentication_strategies']
            : [];
        $strategies['passkey'] = ['enabled' => $request->boolean('enabled')];
        $userSettings['authentication_strategies'] = $strategies;
        $env->forceFill(['user_settings' => $userSettings])->save();

        return back(303)->with('authentication_strategy_saved', 'passkey');
    }

    /**
     * @return array{default_locale: string, fallback_locale: string, supported_locales: list<string>, overrides: array<string, array<string, string>>}
     */
    private function localizationShape(Environment $env): array
    {
        $loc = is_array($env->localization) ? $env->localization : [];
        $overrides = [];
        if (is_array($loc['overrides'] ?? null)) {
            foreach ($loc['overrides'] as $locale => $entries) {
                if (! is_string($locale) || ! is_array($entries)) {
                    continue;
                }
                $clean = [];
                foreach ($entries as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $clean[$key] = $value;
                    }
                }
                $overrides[$locale] = $clean;
            }
        }

        return [
            'default_locale' => is_string($loc['default_locale'] ?? null) ? (string) $loc['default_locale'] : 'en-US',
            'fallback_locale' => is_string($loc['fallback_locale'] ?? null) ? (string) $loc['fallback_locale'] : 'en-US',
            'supported_locales' => is_array($loc['supported_locales'] ?? null)
                ? array_values(array_map('strval', $loc['supported_locales']))
                : ['en-US'],
            'overrides' => $overrides,
        ];
    }
}
