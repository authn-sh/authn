<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Auth\Oauth\Exceptions\OauthDiscoveryFailedException;
use App\Auth\Oauth\OauthProviderResolver;
use App\Auth\Oauth\PresetRegistry;
use App\Http\Resources\OauthProviderResource;
use App\Localization\CanonicalSchema;
use App\Models\AllowlistIdentifier;
use App\Models\ApiKey;
use App\Models\AuthorizationGrant;
use App\Models\BlocklistIdentifier;
use App\Models\EmailTemplate;
use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Invitation;
use App\Models\JwtTemplate;
use App\Models\OauthApplication;
use App\Models\OauthProvider;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\OrganizationMembershipRequest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Session;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Services\Keys\KeyGenerator;
use App\Settings\MultiFactorSettings;
use App\Settings\PasskeySettings;
use App\Support\RoutingLabel;
use App\Support\Url;
use App\Webhooks\Emitter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

/**
 * Dashboard pages — Inertia + React, server-side data prep without proxying
 * through the BAPI (faster, avoids exposing sk_… in the operator browser).
 *
 * The middleware (RequireAdminSession + HandleDashboardInertia) has already
 * resolved the operator User, the workspace membership, the active Project
 * and Environment when the URL embeds them. Each method just queries the
 * env-scoped data it needs and hands it to React.
 */
final class DashboardController
{
    public function __construct(
        private readonly KeyGenerator $keyGenerator,
        private readonly OauthProviderResolver $oauthResolver,
        private readonly PresetRegistry $oauthPresets,
    ) {}

    public function home(): InertiaResponse|RedirectResponse
    {
        $workspace = $this->workspace();
        if ($workspace === null) {
            return redirect(Url::dashboardPathPrefix().'/create-workspace');
        }
        $project = $this->firstProjectOutsideAdmin();
        $env = $project?->environments()->where('kind', Environment::KIND_PRODUCTION)->first()
            ?? $project?->environments()->first();
        if ($project === null || $env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return redirect(Url::dashboardPathPrefix()."/{$project->slug}/{$env->slug}/overview");
    }

    public function createWorkspace(): InertiaResponse
    {
        return Inertia::render('Dashboard/CreateWorkspace', []);
    }

    public function createProject(): InertiaResponse
    {
        return Inertia::render('Dashboard/CreateProject', []);
    }

    public function storeProject(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        if ($workspace === null) {
            return redirect(Url::dashboardPathPrefix().'/create-workspace');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'regex:/^[a-z0-9-]{3,40}$/',
                'not_in:_admin',
                Rule::unique('projects', 'slug')->where('owner_organization_id', $workspace->organization_id),
            ],
        ]);

        [$project, $env] = DB::transaction(function () use ($request, $workspace): array {
            $project = Project::query()->withoutGlobalScopes()->create([
                'name' => (string) $request->input('name'),
                'slug' => (string) $request->input('slug'),
                'owner_organization_id' => $workspace->organization_id,
            ]);
            $env = Environment::query()->withoutGlobalScopes()->create([
                'project_id' => $project->id,
                'kind' => Environment::KIND_PRODUCTION,
                'slug' => 'production',
                'routing_label' => RoutingLabel::generate(),
                'allowed_origins' => [],
            ]);
            ApiKey::query()->create([
                'environment_id' => $env->id,
                'kind' => ApiKey::KIND_PUBLISHABLE,
                'prefix' => 'pk_'.$env->keyEnvironmentSegment().'_',
                'hashed_secret' => $this->keyGenerator->hash($this->keyGenerator->publishableKey($env)),
                'name' => 'Default publishable key',
            ]);

            return [$project, $env];
        });

        return redirect(Url::dashboardPathPrefix()."/{$project->slug}/{$env->slug}/overview");
    }

    public function overview(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/Overview', [
            'counts' => [
                'users' => User::query()->withoutGlobalScopes()->where('environment_id', $env->id)->count(),
                'sessions_active' => Session::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->whereIn('status', Session::LIVE_STATUSES)->count(),
                'invitations_pending' => Invitation::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->where('status', Invitation::STATUS_PENDING)->count(),
            ],
        ]);
    }

    public function users(Request $request, string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $query = User::query()->withoutGlobalScopes()->where('environment_id', $env->id);
        if ($request->filled('q')) {
            $needle = '%'.strtolower((string) $request->input('q')).'%';
            $query->where(function ($q) use ($needle): void {
                $q->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$needle]);
            });
        }
        $rows = $query->latest('created_at')->limit(50)->get();

        return Inertia::render('Dashboard/Users', [
            'users' => $rows->map(fn (User $u) => [
                'id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'username' => $u->username,
                'banned' => (bool) $u->banned,
                'locked' => (bool) $u->locked,
                'created_at' => $u->created_at?->getTimestampMs(),
            ])->all(),
            'query' => (string) $request->input('q', ''),
        ]);
    }

    public function sessions(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $rows = Session::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->latest('last_active_at')
            ->limit(50)
            ->get();

        return Inertia::render('Dashboard/Sessions', [
            'sessions' => $rows->map(fn (Session $s) => [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'status' => $s->status,
                'last_active_at' => $s->last_active_at?->getTimestampMs(),
                'expire_at' => $s->expire_at->getTimestampMs(),
            ])->all(),
        ]);
    }

    public function invitations(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/Invitations', [
            'invitations' => Invitation::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn (Invitation $i) => [
                    'id' => $i->id,
                    'email_address' => $i->email_address,
                    'status' => $i->status,
                    'expires_at' => $i->expires_at?->getTimestampMs(),
                    'created_at' => $i->created_at?->getTimestampMs(),
                ])->all(),
        ]);
    }

    public function restrictions(string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $tab = in_array($tab, ['allowlist', 'blocklist'], true) ? $tab : 'allowlist';

        if ($tab === 'blocklist') {
            return Inertia::render('Dashboard/Configure/Restrictions/Blocklist', [
                'rows' => BlocklistIdentifier::query()->withoutGlobalScopes()->where('environment_id', $env->id)->get()
                    ->map(fn (BlocklistIdentifier $r) => ['id' => $r->id, 'identifier' => $r->identifier])->all(),
            ]);
        }

        return Inertia::render('Dashboard/Configure/Restrictions/Allowlist', [
            'rows' => AllowlistIdentifier::query()->withoutGlobalScopes()->where('environment_id', $env->id)->get()
                ->map(fn (AllowlistIdentifier $r) => ['id' => $r->id, 'identifier' => $r->identifier, 'notify' => (bool) $r->notify])->all(),
        ]);
    }

    public function usersTab(Request $request, string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        return match ($tab) {
            'invitations' => $this->invitations($project_slug, $env_slug),
            'sessions' => $this->sessions($project_slug, $env_slug),
            default => $this->users($request, $project_slug, $env_slug),
        };
    }

    private const AUTH_SECTIONS = ['sign-in', 'sign-up', 'mfa', 'providers', 'enterprise-sso', 'jwt-templates'];

    private const BRANDING_SECTIONS = ['appearance', 'localization'];

    public function authentication(string $project_slug, string $env_slug, ?string $section = null): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $section = in_array($section, self::AUTH_SECTIONS, true) ? $section : 'sign-in';
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];

        return match ($section) {
            'sign-in' => Inertia::render('Dashboard/Configure/Authentication/SignIn'),
            'sign-up' => Inertia::render('Dashboard/Configure/Authentication/SignUp'),
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

public function updateAppearance(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'variables' => ['sometimes', 'array'],
            'elements' => ['sometimes', 'array'],
            'layout' => ['sometimes', 'array'],
        ]);

        $before = Environment::defaultAppearance();
        $stored = is_array($env->appearance) ? $env->appearance : [];
        foreach (['variables', 'elements', 'layout'] as $axis) {
            if (isset($stored[$axis]) && is_array($stored[$axis])) {
                $before[$axis] = $stored[$axis];
            }
        }

        $next = Environment::defaultAppearance();
        foreach (['variables', 'elements', 'layout'] as $axis) {
            if (is_array($request->input($axis))) {
                $next[$axis] = array_filter(
                    $request->input($axis),
                    static fn ($v) => is_string($v) || is_bool($v) || is_int($v) || is_float($v),
                );
            }
        }

        $env->forceFill(['appearance' => $next])->save();

        if ($before != $next) {
            app(Emitter::class)->emit(
                'instance.config.appearance_updated',
                [
                    'environment_id' => $env->id,
                    'previous' => $before,
                    'current' => $next,
                ],
                $env,
            );
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/appearance")
            ->with('appearance_saved', true);
    }

    public function updateLocalization(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'default_locale' => ['required', 'string'],
            'fallback_locale' => ['required', 'string'],
            'supported_locales' => ['required', 'array'],
            'supported_locales.*' => ['string'],
            'overrides' => ['sometimes', 'array'],
            'overrides.*' => ['array'],
        ]);

        $supported = array_values(array_map('strval', (array) $request->input('supported_locales', [])));
        $defaultLocale = (string) $request->input('default_locale');
        $fallbackLocale = (string) $request->input('fallback_locale');

        if (! in_array($defaultLocale, $supported, true) || ! in_array($fallbackLocale, $supported, true)) {
            return back()->withErrors([
                'supported_locales' => 'default_locale and fallback_locale must be in supported_locales.',
            ]);
        }
        $canonical = CanonicalSchema::keys();
        $rawOverrides = is_array($request->input('overrides')) ? (array) $request->input('overrides') : [];
        $overrides = [];
        $unknownKeys = [];
        foreach ($rawOverrides as $locale => $entries) {
            if (! is_string($locale) || ! is_array($entries)) {
                continue;
            }
            $clean = [];
            foreach ($entries as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    continue;
                }
                if (! in_array($key, $canonical, true)) {
                    $unknownKeys[] = "{$locale}.{$key}";

                    continue;
                }
                $clean[$key] = $value;
            }
            $overrides[$locale] = $clean;
        }

        if ($unknownKeys !== []) {
            return back()->withErrors([
                'overrides' => 'Unknown localization keys: '.implode(', ', $unknownKeys),
            ]);
        }

        $env->forceFill([
            'localization' => [
                'default_locale' => $defaultLocale,
                'fallback_locale' => $fallbackLocale,
                'supported_locales' => $supported,
                'overrides' => $overrides,
            ],
        ])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/localization")
            ->with('localization_saved', true);
    }

    public function updateSmsTemplate(Request $request, string $project_slug, string $env_slug, string $slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'body' => ['nullable', 'string', 'max:1600'],
            'delivered_by_us' => ['nullable', 'boolean'],
            'from_number_override' => ['nullable', 'string', 'max:32'],
        ]);

        $row = SmsTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('slug', $slug)
            ->first();
        if ($row === null) {
            return redirect()->back()->withErrors(['slug' => 'Template not found.']);
        }

        $patch = array_filter([
            'body' => $request->input('body'),
            'delivered_by_us' => $request->has('delivered_by_us') ? $request->boolean('delivered_by_us') : null,
            'from_number_override' => $request->input('from_number_override'),
        ], static fn ($v) => $v !== null);
        $row->forceFill($patch)->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/sms")
            ->with('sms_template_saved', true);
    }

    public function provider(string $project_slug, string $env_slug, string $provider_key): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $row = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('provider_key', $provider_key)
            ->first();
        $preset = $this->oauthPresets->get($provider_key);

        if ($row === null && $preset === null) {
            abort(404);
        }

        return Inertia::render('Dashboard/Provider', [
            'provider' => $row !== null ? $this->oauthRowShape($row) : null,
            'preset' => $preset === null ? null : [
                'key' => $preset->key(),
                'name' => $preset->name(),
                'default_scopes' => $preset->defaultScopes(),
                'authorization_endpoint' => $preset->authorizationEndpoint(),
                'token_endpoint' => $preset->tokenEndpoint(),
                'userinfo_endpoint' => $preset->userinfoEndpoint(),
                'issuer' => $preset->issuer(),
            ],
            'provider_key' => $provider_key,
            'docs_url' => "https://authn.sh/docs/providers/{$provider_key}",
        ]);
    }

    public function newCustomOauthProvider(Request $request, string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $kind = (string) $request->route()->defaults['kind'];

        return Inertia::render('Dashboard/Provider', [
            'provider' => null,
            'preset' => null,
            'provider_key' => null,
            'new_kind' => $kind,
            'docs_url' => $kind === 'custom_oidc'
                ? 'https://authn.sh/docs/providers/custom-oidc'
                : 'https://authn.sh/docs/providers/custom-oauth2',
        ]);
    }

    public function storeOauthProvider(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'provider_kind' => ['required', Rule::in(OauthProvider::KINDS)],
            'provider_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1024'],
            'issuer' => ['nullable', 'url', 'max:512'],
            'authorization_endpoint' => ['nullable', 'url', 'max:512'],
            'token_endpoint' => ['nullable', 'url', 'max:512'],
            'userinfo_endpoint' => ['nullable', 'url', 'max:512'],
            'userinfo_method' => ['nullable', 'in:GET,POST'],
            'userinfo_auth' => ['nullable', 'in:bearer,basic,query'],
            'scopes' => ['nullable', 'array'],
            'attribute_mapping' => ['nullable', 'array'],
            'additional_authorization_params' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $kind = (string) $request->input('provider_kind');
        $providerKey = (string) $request->input('provider_key');

        $duplicate = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('provider_key', $providerKey)
            ->exists();
        if ($duplicate) {
            return redirect()->back()->withErrors(['provider_key' => 'A provider with that key already exists.']);
        }

        $payload = [
            'environment_id' => $env->id,
            'provider_kind' => $kind,
            'provider_key' => $providerKey,
            'name' => (string) $request->input('name'),
            'enabled' => $request->boolean('enabled', false),
            'client_id' => (string) $request->input('client_id'),
            'encrypted_client_secret' => (string) $request->input('client_secret', ''),
            'scopes' => is_array($request->input('scopes')) ? $request->input('scopes') : [],
            'attribute_mapping' => is_array($request->input('attribute_mapping')) ? $request->input('attribute_mapping') : [],
            'additional_authorization_params' => is_array($request->input('additional_authorization_params')) ? $request->input('additional_authorization_params') : [],
        ];

        if ($kind === OauthProvider::KIND_PRESET) {
            $preset = $this->oauthPresets->get($providerKey);
            if ($preset !== null) {
                $payload['authorization_endpoint'] = $preset->authorizationEndpoint();
                $payload['token_endpoint'] = $preset->tokenEndpoint();
                $payload['userinfo_endpoint'] = $preset->userinfoEndpoint();
                $payload['jwks_uri'] = $preset->jwksUri();
                $payload['id_token_signing_algs'] = $preset->idTokenSigningAlgs();
                $payload['userinfo_method'] = $preset->userinfoMethod();
                $payload['userinfo_auth'] = $preset->userinfoAuth();
                if ($payload['scopes'] === []) {
                    $payload['scopes'] = $preset->defaultScopes();
                }
                if ($payload['attribute_mapping'] === []) {
                    $payload['attribute_mapping'] = $preset->defaultAttributeMapping();
                }
                if ($payload['additional_authorization_params'] === []) {
                    $payload['additional_authorization_params'] = $preset->additionalAuthorizationParams();
                }
            }
        }

        if ($kind === OauthProvider::KIND_CUSTOM_OIDC) {
            $issuer = (string) $request->input('issuer');
            if ($issuer === '') {
                return redirect()->back()->withErrors(['issuer' => 'issuer is required for custom OIDC.']);
            }
            try {
                $endpoints = $this->oauthResolver->discoverEndpoints($issuer);
                $payload = array_merge($payload, [
                    'issuer' => $issuer,
                    'authorization_endpoint' => $endpoints['authorization_endpoint'],
                    'token_endpoint' => $endpoints['token_endpoint'],
                    'userinfo_endpoint' => $endpoints['userinfo_endpoint'],
                    'jwks_uri' => $endpoints['jwks_uri'],
                    'id_token_signing_algs' => $endpoints['id_token_signing_algs'],
                    'discovery_cached_at' => now(),
                ]);
            } catch (OauthDiscoveryFailedException $e) {
                return redirect()->back()->withErrors(['issuer' => $e->getMessage()]);
            }
        }

        if ($kind === OauthProvider::KIND_CUSTOM_OAUTH2) {
            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'userinfo_method', 'userinfo_auth'] as $field) {
                if (! $request->filled($field)) {
                    return redirect()->back()->withErrors([$field => "{$field} is required for custom OAuth2."]);
                }
                $payload[$field] = $request->input($field);
            }
        }

        $created = OauthProvider::query()->withoutGlobalScopes()->create($payload);

        app(Emitter::class)->emit('oauthProvider.created', OauthProviderResource::from($created), $env);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/social-providers")
            ->with('oauth_provider_saved', true);
    }

    public function updateOauthProvider(Request $request, string $project_slug, string $env_slug, string $oauth_provider_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_provider_id)
            ->first();
        if ($row === null) {
            return redirect()->back()->withErrors(['oauth_provider_id' => 'Provider not found.']);
        }

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'allow_sign_in' => ['sometimes', 'boolean'],
            'allow_sign_up' => ['sometimes', 'boolean'],
            'block_email_subaddresses' => ['sometimes', 'boolean'],
            'client_id' => ['sometimes', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1024'],
            'scopes' => ['sometimes', 'array'],
            'attribute_mapping' => ['sometimes', 'array'],
            'additional_authorization_params' => ['sometimes', 'array'],
        ]);

        $patch = [];
        foreach (['name', 'enabled', 'allow_sign_in', 'allow_sign_up', 'block_email_subaddresses', 'client_id', 'scopes', 'attribute_mapping', 'additional_authorization_params'] as $field) {
            if ($request->has($field)) {
                $patch[$field] = $request->input($field);
            }
        }
        // Only rotate the secret on a non-empty value.
        $secret = $request->input('client_secret');
        if (is_string($secret) && $secret !== '') {
            $patch['encrypted_client_secret'] = $secret;
        }

        $row->forceFill($patch)->save();

        app(Emitter::class)->emit('oauthProvider.updated', OauthProviderResource::from($row->refresh()), $env);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/social-providers")
            ->with('oauth_provider_saved', true);
    }

    public function testOauthProvider(string $project_slug, string $env_slug, string $oauth_provider_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_provider_id)
            ->first();
        if ($row === null) {
            return redirect()->back()->withErrors(['oauth_provider_id' => 'Provider not found.']);
        }

        $errors = [];
        $userinfoStatus = null;
        try {
            $resolved = $this->oauthResolver->resolve($row);
            if ($resolved->userinfoEndpoint !== '' && $resolved->userinfoEndpoint !== $resolved->tokenEndpoint) {
                try {
                    $userinfoStatus = Http::timeout(5)->get($resolved->userinfoEndpoint)->status();
                } catch (ConnectionException|RequestException|Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } catch (OauthDiscoveryFailedException $e) {
            $errors[] = $e->getMessage();
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/social-providers")
            ->with('oauth_provider_test', [
                'provider_id' => $row->id,
                'userinfo_status' => $userinfoStatus,
                'errors' => $errors,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function oauthRowShape(OauthProvider $row): array
    {
        return [
            'id' => $row->id,
            'provider_kind' => $row->provider_kind,
            'provider_key' => $row->provider_key,
            'name' => $row->name,
            'enabled' => (bool) $row->enabled,
            'allow_sign_in' => (bool) $row->allow_sign_in,
            'allow_sign_up' => (bool) $row->allow_sign_up,
            'block_email_subaddresses' => (bool) $row->block_email_subaddresses,
            'client_id' => (string) $row->client_id,
            'client_secret_set' => $row->encrypted_client_secret !== '',
            'scopes' => is_array($row->scopes) ? array_values($row->scopes) : [],
            'attribute_mapping' => is_array($row->attribute_mapping) ? $row->attribute_mapping : [],
            'additional_authorization_params' => is_array($row->additional_authorization_params) ? $row->additional_authorization_params : [],
            'issuer' => $row->issuer,
            'authorization_endpoint' => $row->authorization_endpoint,
            'token_endpoint' => $row->token_endpoint,
            'userinfo_endpoint' => $row->userinfo_endpoint,
            'redirect_uri' => $row->computeRedirectUri(),
        ];
    }

    public function templates(string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $tab = in_array($tab, ['email', 'sms'], true) ? $tab : 'email';

        if ($tab === 'sms') {
            return Inertia::render('Dashboard/Configure/Templates/Sms', [
                'templates' => SmsTemplate::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->orderBy('slug')
                    ->get(['id', 'slug', 'body', 'delivered_by_us', 'from_number_override'])
                    ->map(fn (SmsTemplate $t) => [
                        'id' => $t->id,
                        'slug' => $t->slug,
                        'body' => $t->body,
                        'delivered_by_us' => (bool) $t->delivered_by_us,
                        'from_number_override' => $t->from_number_override,
                    ])->all(),
            ]);
        }

        return Inertia::render('Dashboard/Configure/Templates/Email', [
            'templates' => EmailTemplate::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->orderBy('slug')
                ->get(['id', 'slug', 'subject', 'delivered_by_us', 'updated_at'])
                ->map(fn (EmailTemplate $t) => [
                    'id' => $t->id,
                    'slug' => $t->slug,
                    'subject' => $t->subject,
                    'delivered_by_us' => (bool) $t->delivered_by_us,
                    'updated_at' => $t->updated_at?->getTimestampMs(),
                ])->all(),
        ]);
    }

    public function domains(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        if ($this->env($project_slug, $env_slug) === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/Domains', []);
    }

    public function redirects(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        if ($this->env($project_slug, $env_slug) === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/Redirects', []);
    }

    public function idpAttributes(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        if ($this->env($project_slug, $env_slug) === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/IdpAttributes', []);
    }

    public function applications(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $rows = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->whereNull('removed_at')
            ->orderBy('name')
            ->get();
        $grantCounts = AuthorizationGrant::query()->withoutGlobalScopes()
            ->whereIn('oauth_application_id', $rows->pluck('id'))
            ->whereNull('revoked_at')
            ->selectRaw('oauth_application_id, count(*) as c')
            ->groupBy('oauth_application_id')
            ->pluck('c', 'oauth_application_id');

        return Inertia::render('Dashboard/Applications', [
            'applications' => $rows->map(fn (OauthApplication $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'client_id' => $a->client_id,
                'callback_urls' => is_array($a->callback_urls) ? array_values($a->callback_urls) : [],
                'scopes' => is_array($a->scopes) ? array_values($a->scopes) : [],
                'is_public' => (bool) $a->is_public,
                'grants_count' => (int) ($grantCounts[$a->id] ?? 0),
                'created_at' => $a->created_at?->getTimestampMs(),
            ])->all(),
        ]);
    }

    public function newApplication(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        if ($this->env($project_slug, $env_slug) === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/NewApplication');
    }

    public function apiKeys(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/ApiKeys', [
            'keys' => ApiKey::query()
                ->where('environment_id', $env->id)
                ->latest('created_at')
                ->get(['id', 'kind', 'prefix', 'name', 'last_used_at', 'revoked_at'])
                ->map(fn (ApiKey $k) => [
                    'id' => $k->id,
                    'kind' => $k->kind,
                    'prefix' => $k->prefix,
                    'name' => $k->name,
                    'last_used_at' => $k->last_used_at?->getTimestampMs(),
                    'revoked_at' => $k->revoked_at?->getTimestampMs(),
                ])->all(),
        ]);
    }

    public function rotateApiKey(string $project_slug, string $env_slug, string $id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $existing = ApiKey::query()->where('environment_id', $env->id)->where('id', $id)->first();
        if ($existing === null) {
            return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/api-keys");
        }

        $plaintext = $existing->kind === ApiKey::KIND_SECRET
            ? $this->keyGenerator->secretKey($env)
            : $this->keyGenerator->publishableKey($env);
        $existing->forceFill([
            'prefix' => substr($plaintext, 0, 16),
            'hashed_secret' => $this->keyGenerator->hash($plaintext),
            'last_used_at' => null,
        ])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/api-keys")
            ->with('rotated_secret', $plaintext);
    }

    public function webhooks(string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $tab = in_array($tab, ['endpoints', 'deliveries'], true) ? $tab : 'endpoints';

        if ($tab === 'deliveries') {
            $endpointIds = WebhookEndpoint::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->pluck('id');

            return Inertia::render('Dashboard/Configure/Webhooks/Deliveries', [
                'deliveries' => WebhookDelivery::query()
                    ->whereIn('webhook_endpoint_id', $endpointIds)
                    ->latest('created_at')
                    ->limit(100)
                    ->get()
                    ->map(fn (WebhookDelivery $d) => [
                        'id' => $d->id,
                        'webhook_endpoint_id' => $d->webhook_endpoint_id,
                        'webhook_event_id' => $d->webhook_event_id,
                        'attempt' => $d->attempt,
                        'status' => $d->status,
                        'response_status' => $d->response_status,
                        'created_at' => $d->created_at?->getTimestampMs(),
                    ])->all(),
            ]);
        }

        return Inertia::render('Dashboard/Configure/Webhooks/Endpoints', [
            'endpoints' => WebhookEndpoint::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->latest('created_at')
                ->get()
                ->map(fn (WebhookEndpoint $e) => [
                    'id' => $e->id,
                    'url' => $e->url,
                    'enabled' => (bool) $e->enabled,
                    'enabled_event_types' => is_array($e->enabled_event_types) ? $e->enabled_event_types : [],
                    'signing_secret_prefix' => $e->secretPrefix(),
                    'rotation_window_expires_at' => $e->prior_signing_secret_expires_at?->getTimestampMs(),
                ])->all(),
        ]);
    }

    public function storeWebhook(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $request->validate([
            'url' => ['required', 'url'],
            'enabled_event_types' => ['nullable', 'array'],
        ]);
        $row = WebhookEndpoint::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'url' => (string) $request->input('url'),
            'signing_secret' => WebhookEndpoint::mintSecret(),
            'enabled_event_types' => $request->input('enabled_event_types', ['*']),
            'enabled' => true,
        ]);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/webhooks")
            ->with('signing_secret', $row->displaySecret());
    }

    public function auditLog(Request $request, string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $query = WebhookEvent::query()->withoutGlobalScopes()->where('environment_id', $env->id);
        $filter = (string) $request->input('type', '');
        if ($filter !== '') {
            $query->where('type', 'like', $filter.'%');
        }
        $entries = $query->latest('created_at')->limit(100)->get();

        return Inertia::render('Dashboard/AuditLog', [
            'note' => 'Operator audit feed: all webhook events for this environment. Full audit log lands in v0.8 (PLAN §15.5).',
            'filter' => $filter,
            'entries' => $entries->map(fn (WebhookEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'was_test' => (bool) $e->was_test,
                'data' => $e->data,
                'created_at' => $e->created_at?->getTimestampMs(),
            ])->all(),
        ]);
    }

    public function workspaceSettings(): InertiaResponse|RedirectResponse
    {
        $workspace = $this->workspace();
        if ($workspace === null) {
            return redirect(Url::dashboardPathPrefix().'/create-workspace');
        }

        return Inertia::render('Dashboard/WorkspaceSettings', [
            'membership' => [
                'organization_id' => $workspace->organization_id,
                'role' => $workspace->role?->key,
            ],
        ]);
    }

    public function organizations(Request $request, string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $query = Organization::query()->withoutGlobalScopes()->where('environment_id', $env->id);
        if ($request->filled('q')) {
            $needle = '%'.strtolower((string) $request->input('q')).'%';
            $query->where(function ($q) use ($needle): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$needle]);
            });
        }
        $rows = $query->latest('created_at')->limit(50)->get();

        return Inertia::render('Dashboard/Organizations', [
            'organizations' => $rows->map(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'members_count' => (int) $o->members_count,
                'pending_invitations_count' => (int) $o->pending_invitations_count,
                'admin_delete_enabled' => (bool) $o->admin_delete_enabled,
                'created_at' => $o->created_at?->getTimestampMs(),
            ])->all(),
            'query' => (string) $request->input('q', ''),
        ]);
    }

    public function organization(Request $request, string $project_slug, string $env_slug, string $organization_id): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $org = Organization::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $organization_id)
            ->first();
        if ($org === null) {
            return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/organizations");
        }

        $tab = (string) $request->input('tab', 'members');

        $members = OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->with(['user', 'role'])
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (OrganizationMembership $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'role' => $m->role?->key,
                'username' => $m->user?->username,
                'first_name' => $m->user?->first_name,
                'last_name' => $m->user?->last_name,
                'created_at' => $m->created_at?->getTimestampMs(),
            ])->all();

        $invitations = OrganizationInvitation::query()
            ->where('organization_id', $org->id)
            ->with('role')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (OrganizationInvitation $i) => [
                'id' => $i->id,
                'email_address' => $i->email_address,
                'role' => $i->role?->key,
                'status' => $i->status,
                'expires_at' => $i->expires_at?->getTimestampMs(),
                'created_at' => $i->created_at?->getTimestampMs(),
            ])->all();

        $requests = OrganizationMembershipRequest::query()
            ->where('organization_id', $org->id)
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (OrganizationMembershipRequest $r) => [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'status' => $r->status,
                'created_at' => $r->created_at?->getTimestampMs(),
            ])->all();

        $domains = OrganizationDomain::query()
            ->where('organization_id', $org->id)
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (OrganizationDomain $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'verified' => (bool) $d->verified,
                'enrollment_mode' => $d->enrollment_mode,
                'total_pending_invitations' => (int) $d->total_pending_invitations,
                'created_at' => $d->created_at?->getTimestampMs(),
            ])->all();

        return Inertia::render('Dashboard/Organization', [
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'members_count' => (int) $org->members_count,
                'pending_invitations_count' => (int) $org->pending_invitations_count,
                'max_allowed_memberships' => $org->max_allowed_memberships,
                'admin_delete_enabled' => (bool) $org->admin_delete_enabled,
                'public_metadata' => is_array($org->public_metadata) ? $org->public_metadata : [],
                'created_at' => $org->created_at?->getTimestampMs(),
            ],
            'tab' => $tab,
            'members' => $members,
            'invitations' => $invitations,
            'membership_requests' => $requests,
            'domains' => $domains,
        ]);
    }

    public function authorization(string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        $tab = in_array($tab, ['roles', 'permissions'], true) ? $tab : 'roles';
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        if ($tab === 'permissions') {
            return Inertia::render('Dashboard/Configure/Authorization/Permissions', [
                'permissions' => Permission::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->orderBy('key')
                    ->get()
                    ->map(fn (Permission $p) => [
                        'id' => $p->id,
                        'key' => $p->key,
                        'name' => $p->name,
                        'description' => $p->description,
                        'is_system' => (bool) $p->is_system,
                    ])->all(),
            ]);
        }

        return Inertia::render('Dashboard/Configure/Authorization/Roles', [
            'roles' => Role::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->with('permissions')
                ->orderBy('is_system', 'desc')
                ->orderBy('key')
                ->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id,
                    'key' => $r->key,
                    'name' => $r->name,
                    'description' => $r->description,
                    'is_system' => (bool) $r->is_system,
                    'is_default' => (bool) $r->is_default,
                    'is_creator_eligible' => (bool) $r->is_creator_eligible,
                    'permissions' => $r->permissions->pluck('key')->all(),
                ])->all(),
        ]);
    }

    /* -------------------- helpers -------------------- */

    private function env(string $projectSlug, string $envSlug): ?Environment
    {
        $project = Project::query()->withoutGlobalScopes()->where('slug', $projectSlug)->first();
        if ($project === null) {
            return null;
        }

        return $project->environments()->where('slug', $envSlug)->first();
    }

    private function workspace(): ?OrganizationMembership
    {
        $workspace = app()->bound('dashboard.workspace') ? app('dashboard.workspace') : null;

        return $workspace instanceof OrganizationMembership ? $workspace : null;
    }

    private function firstProjectOutsideAdmin(): ?Project
    {
        return Project::query()
            ->withoutGlobalScopes()
            ->where('is_system', false)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function enterpriseConnectionRowShape(EnterpriseConnection $row): array
    {
        $accountsCount = EnterpriseAccount::query()->withoutGlobalScopes()
            ->where('enterprise_connection_id', $row->id)
            ->count();

        return [
            'id' => $row->id,
            'protocol' => $row->protocol,
            'name' => $row->name,
            'enabled' => (bool) $row->enabled,
            'organization_id' => $row->organization_id,
            'domains' => is_array($row->domains) ? array_values($row->domains) : [],
            'default_role' => $row->default_role,
            'saml_idp_entity_id' => $row->saml_idp_entity_id,
            'saml_sso_url' => $row->saml_sso_url,
            'saml_signing_algorithm' => $row->saml_signing_algorithm,
            'oidc_issuer' => $row->oidc_issuer,
            'oidc_client_id' => $row->oidc_client_id,
            'oidc_scopes' => is_array($row->oidc_scopes) ? array_values($row->oidc_scopes) : [],
            'linked_accounts_count' => $accountsCount,
            'created_at' => $row->created_at?->getTimestampMs(),
        ];
    }

    public function storeEnterpriseConnection(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'protocol' => ['required', Rule::in(EnterpriseConnection::PROTOCOLS)],
            'name' => ['required', 'string', 'max:255'],
            'domains' => ['nullable', 'array'],
            'domains.*' => ['string'],
            'default_role' => ['nullable', 'string', 'max:255'],
            'saml_idp_entity_id' => ['nullable', 'string', 'max:512'],
            'saml_sso_url' => ['nullable', 'url', 'max:512'],
            'saml_idp_certificate' => ['nullable', 'string'],
            'saml_signing_algorithm' => ['nullable', 'string', 'max:128'],
            'oidc_issuer' => ['nullable', 'url', 'max:512'],
            'oidc_client_id' => ['nullable', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string'],
            'oidc_scopes' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $protocol = (string) $request->input('protocol');
        $payload = [
            'environment_id' => $env->id,
            'protocol' => $protocol,
            'name' => (string) $request->input('name'),
            'enabled' => $request->boolean('enabled', true),
            'domains' => is_array($request->input('domains')) ? $request->input('domains') : [],
            'default_role' => $request->input('default_role'),
        ];
        if ($protocol === EnterpriseConnection::PROTOCOL_SAML) {
            $payload += [
                'saml_idp_entity_id' => (string) $request->input('saml_idp_entity_id'),
                'saml_sso_url' => (string) $request->input('saml_sso_url'),
                'saml_idp_certificate' => (string) $request->input('saml_idp_certificate'),
                'saml_signing_algorithm' => $request->input('saml_signing_algorithm') ?: 'RSA_SHA256',
            ];
        } else {
            $payload += [
                'oidc_issuer' => (string) $request->input('oidc_issuer'),
                'oidc_client_id' => (string) $request->input('oidc_client_id'),
                'oidc_client_secret' => (string) $request->input('oidc_client_secret'),
                'oidc_scopes' => is_array($request->input('oidc_scopes')) ? $request->input('oidc_scopes') : ['openid', 'email', 'profile'],
            ];
        }

        EnterpriseConnection::query()->withoutGlobalScopes()->create($payload);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/enterprise-sso")
            ->with('enterprise_connection_saved', true);
    }

    public function destroyEnterpriseConnection(Request $request, string $project_slug, string $env_slug, string $enterprise_connection_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $conn = EnterpriseConnection::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $enterprise_connection_id)
            ->first();
        if ($conn !== null) {
            $linked = EnterpriseAccount::query()->withoutGlobalScopes()
                ->where('enterprise_connection_id', $conn->id)
                ->exists();
            if ($linked) {
                return redirect()->back()->withErrors([
                    'enterprise_connection' => 'Cannot delete — accounts are still linked. Unlink users first.',
                ]);
            }
            $conn->delete();
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/enterprise-sso")
            ->with('enterprise_connection_deleted', true);
    }

    // --- JWT Templates (AU-11). ---------------------------------------------

    public function storeJwtTemplate(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]{0,63}$/'],
            'claims' => ['required', 'array'],
            'lifetime' => ['sometimes', 'integer', 'between:1,86400'],
            'allowed_clock_skew' => ['sometimes', 'integer', 'between:0,300'],
            'signing_algorithm' => ['sometimes', 'string', 'in:RS256,ES256,HS256'],
            'custom_signing_key' => ['sometimes', 'nullable', 'string'],
        ]);

        JwtTemplate::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'name' => $data['name'],
            'claims' => $data['claims'],
            'lifetime' => $data['lifetime'] ?? 60,
            'allowed_clock_skew' => $data['allowed_clock_skew'] ?? 5,
            'signing_algorithm' => $data['signing_algorithm'] ?? JwtTemplate::ALG_RS256,
            'custom_signing_key' => $data['custom_signing_key'] ?? null,
        ]);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/jwt-templates")
            ->with('jwt_template_saved', true);
    }

    public function updateJwtTemplate(Request $request, string $project_slug, string $env_slug, string $jwt_template_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = JwtTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $jwt_template_id)
            ->whereNull('removed_at')
            ->first();
        if ($row === null) {
            return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/jwt-templates");
        }

        $data = $request->validate([
            'claims' => ['sometimes', 'array'],
            'lifetime' => ['sometimes', 'integer', 'between:1,86400'],
            'allowed_clock_skew' => ['sometimes', 'integer', 'between:0,300'],
            'custom_signing_key' => ['sometimes', 'nullable', 'string'],
        ]);

        $row->fill(array_intersect_key($data, array_flip(['claims', 'lifetime', 'allowed_clock_skew'])));
        if (array_key_exists('custom_signing_key', $data)) {
            $row->custom_signing_key = $data['custom_signing_key'];
        }
        $row->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/jwt-templates")
            ->with('jwt_template_saved', true);
    }

    public function destroyJwtTemplate(string $project_slug, string $env_slug, string $jwt_template_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = JwtTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $jwt_template_id)
            ->whereNull('removed_at')
            ->first();
        if ($row !== null) {
            $row->delete();
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/jwt-templates")
            ->with('jwt_template_deleted', true);
    }

    // --- OAuth Applications (AU-11). ---------------------------------------

    public function storeOauthApplication(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'callback_urls' => ['required', 'array', 'min:1'],
            'callback_urls.*' => ['required', 'string', 'max:2048'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'max:120'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
        $isPublic = (bool) ($data['is_public'] ?? false);
        $secret = $isPublic ? null : OauthApplication::mintClientSecret();

        $row = OauthApplication::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'name' => $data['name'],
            'hashed_client_secret' => $secret['hash'] ?? null,
            'callback_urls' => array_values($data['callback_urls']),
            'scopes' => array_values($data['scopes'] ?? []),
            'is_public' => $isPublic,
        ]);

        $redirect = redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications")
            ->with('oauth_application_saved', true)
            ->with('oauth_application_id', $row->id);
        if ($secret !== null) {
            $redirect = $redirect->with('oauth_application_secret', $secret['plaintext']);
        }

        return $redirect;
    }

    public function updateOauthApplication(Request $request, string $project_slug, string $env_slug, string $oauth_application_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($row === null) {
            return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications");
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'callback_urls' => ['sometimes', 'array', 'min:1'],
            'callback_urls.*' => ['required', 'string', 'max:2048'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'max:120'],
        ]);

        $row->fill(array_intersect_key($data, array_flip(['name', 'callback_urls', 'scopes'])));
        $row->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications")
            ->with('oauth_application_saved', true);
    }

    public function destroyOauthApplication(string $project_slug, string $env_slug, string $oauth_application_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($row !== null) {
            AuthorizationGrant::query()->withoutGlobalScopes()
                ->where('oauth_application_id', $row->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $row->delete();
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications")
            ->with('oauth_application_deleted', true);
    }

    public function rotateOauthApplicationSecret(string $project_slug, string $env_slug, string $oauth_application_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($row === null || $row->is_public) {
            return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications");
        }

        $secret = OauthApplication::mintClientSecret();
        $row->forceFill(['hashed_client_secret' => $secret['hash']])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/oauth-applications")
            ->with('oauth_application_saved', true)
            ->with('oauth_application_id', $row->id)
            ->with('oauth_application_secret', $secret['plaintext']);
    }
}
