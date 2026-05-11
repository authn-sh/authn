<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Auth\Oauth\Exceptions\OauthDiscoveryFailedException;
use App\Auth\Oauth\OauthProviderResolver;
use App\Auth\Oauth\PresetRegistry;
use App\Http\Resources\OauthProviderResource;
use App\Jobs\Sms\SendSmsTemplate;
use App\Localization\CanonicalSchema;
use App\Models\AllowlistIdentifier;
use App\Models\ApiKey;
use App\Models\BlocklistIdentifier;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\Invitation;
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

    public function allowlist(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/Allowlist', [
            'rows' => AllowlistIdentifier::query()->withoutGlobalScopes()->where('environment_id', $env->id)->get()
                ->map(fn (AllowlistIdentifier $r) => ['id' => $r->id, 'identifier' => $r->identifier, 'notify' => (bool) $r->notify])->all(),
        ]);
    }

    public function blocklist(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/Blocklist', [
            'rows' => BlocklistIdentifier::query()->withoutGlobalScopes()->where('environment_id', $env->id)->get()
                ->map(fn (BlocklistIdentifier $r) => ['id' => $r->id, 'identifier' => $r->identifier])->all(),
        ]);
    }

    public function configure(string $project_slug, string $env_slug, string $section = 'attributes'): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $smsCfg = is_array($userSettings['sms'] ?? null) ? $userSettings['sms'] : [];
        $attributes = is_array($userSettings['attributes'] ?? null) ? $userSettings['attributes'] : [];
        $smsTemplates = SmsTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('slug')
            ->get(['id', 'slug', 'body', 'delivered_by_us', 'from_number_override']);
        $oauthRows = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('provider_kind')
            ->orderBy('provider_key')
            ->get();

        return Inertia::render('Dashboard/Configure', [
            'section' => $section,
            'user_settings' => $userSettings,
            'allowed_origins' => is_array($env->allowed_origins) ? $env->allowed_origins : [],
            'appearance' => is_array($env->appearance) ? $env->appearance : [],
            'signup_mode' => $env->signup_mode,
            'multi_factor' => MultiFactorSettings::fromUserSettings($userSettings)->toArray(),
            'attributes' => [
                'phone_number' => is_string($attributes['phone_number'] ?? null)
                    ? (string) $attributes['phone_number']
                    : 'off',
            ],
            'sms' => [
                'driver' => $smsCfg['driver'] ?? null,
                'from_number' => $smsCfg['from_number'] ?? null,
                'twilio_account_sid' => $smsCfg['twilio']['account_sid'] ?? null,
                'twilio_auth_token_set' => isset($smsCfg['twilio']['auth_token']) && $smsCfg['twilio']['auth_token'] !== '',
                'vonage_api_key' => $smsCfg['vonage']['api_key'] ?? null,
                'vonage_api_secret_set' => isset($smsCfg['vonage']['api_secret']) && $smsCfg['vonage']['api_secret'] !== '',
            ],
            'sms_templates' => $smsTemplates->map(fn (SmsTemplate $t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'body' => $t->body,
                'delivered_by_us' => (bool) $t->delivered_by_us,
                'from_number_override' => $t->from_number_override,
            ])->all(),
            'oauth_providers' => $oauthRows->map(fn (OauthProvider $p) => $this->oauthRowShape($p))->all(),
            'oauth_preset_keys' => $this->oauthPresets->keys(),
            'localization' => $this->localizationShape($env),
            'localization_canonical' => [
                'shipped_locales' => CanonicalSchema::SHIPPED_LOCALES,
                'fallback_locale' => CanonicalSchema::FALLBACK_LOCALE,
                'keys' => CanonicalSchema::keys(),
                'en_us_catalog' => CanonicalSchema::catalog('en-US'),
            ],
        ]);
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
        ]);

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $previous = MultiFactorSettings::fromUserSettings($userSettings);
        $next = $previous->withPatch([
            'totp' => ['enabled' => $request->boolean('totp.enabled')],
            'backup_codes' => [
                'enabled' => $request->boolean('backup_codes.enabled'),
                'default_count' => (int) $request->input('backup_codes.default_count'),
            ],
        ]);
        $userSettings['multi_factor'] = $next->toArray();
        $env->forceFill(['user_settings' => $userSettings])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/multi-factor")
            ->with('multi_factor_saved', true);
    }

    public function updateAttributes(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'phone_number' => ['required', 'in:required,optional,off'],
        ]);

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $attributes = is_array($userSettings['attributes'] ?? null) ? $userSettings['attributes'] : [];
        $attributes['phone_number'] = (string) $request->input('phone_number');
        $userSettings['attributes'] = $attributes;
        $env->forceFill(['user_settings' => $userSettings])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/attributes")
            ->with('attributes_saved', true);
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

    public function updateSms(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'driver' => ['nullable', 'in:twilio,vonage'],
            'from_number' => ['nullable', 'string', 'max:32'],
            'twilio.account_sid' => ['nullable', 'string', 'max:255'],
            'twilio.auth_token' => ['nullable', 'string', 'max:255'],
            'vonage.api_key' => ['nullable', 'string', 'max:255'],
            'vonage.api_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $sms = is_array($userSettings['sms'] ?? null) ? $userSettings['sms'] : [];

        $sms['driver'] = $request->input('driver');
        $sms['from_number'] = $request->input('from_number');

        // Credential write-only semantics: empty / null leaves the stored value
        // untouched (so the UI can render `•••• rotate` placeholders without
        // wiping the secret on every save). Operators that need to clear a
        // secret should rotate via the BAPI `Environment` patch.
        foreach (['twilio' => ['account_sid', 'auth_token'], 'vonage' => ['api_key', 'api_secret']] as $vendor => $keys) {
            $vendorBlock = is_array($sms[$vendor] ?? null) ? $sms[$vendor] : [];
            foreach ($keys as $field) {
                if (! $request->has("{$vendor}.{$field}")) {
                    continue;
                }
                $value = $request->input("{$vendor}.{$field}");
                if ($value === '' || $value === null) {
                    continue;
                }
                $vendorBlock[$field] = $value;
            }
            if ($vendorBlock !== []) {
                $sms[$vendor] = $vendorBlock;
            }
        }

        $userSettings['sms'] = $sms;
        $env->forceFill(['user_settings' => $userSettings])->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/sms")
            ->with('sms_saved', true);
    }

    public function sendTestSms(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'to_number' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);

        SendSmsTemplate::dispatch(
            $env->id,
            SmsTemplate::SLUG_VERIFICATION_CODE,
            (string) $request->input('to_number'),
            ['otp_code' => '424242', 'expiry_minutes' => 10],
        );

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/sms")
            ->with('sms_test_dispatched', true);
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

    public function destroyOauthProvider(string $project_slug, string $env_slug, string $oauth_provider_id): RedirectResponse
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

        $linked = ExternalAccount::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('oauth_provider_id', $row->id)
            ->exists();
        if ($linked) {
            return redirect()->back()->withErrors(['oauth_provider_id' => 'ExternalAccount rows still link to this provider.']);
        }

        $snapshot = OauthProviderResource::from($row);
        $row->delete();

        app(Emitter::class)->emit('oauthProvider.deleted', $snapshot, $env);

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

    public function emailTemplates(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/EmailTemplates', [
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

    public function webhooks(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $endpoints = WebhookEndpoint::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->latest('created_at')
            ->get();
        $deliveries = WebhookDelivery::query()
            ->whereIn('webhook_endpoint_id', $endpoints->pluck('id'))
            ->latest('created_at')
            ->limit(50)
            ->get();

        return Inertia::render('Dashboard/Webhooks', [
            'endpoints' => $endpoints->map(fn (WebhookEndpoint $e) => [
                'id' => $e->id,
                'url' => $e->url,
                'enabled' => (bool) $e->enabled,
                'enabled_event_types' => is_array($e->enabled_event_types) ? $e->enabled_event_types : [],
                'signing_secret_prefix' => $e->secretPrefix(),
                'rotation_window_expires_at' => $e->prior_signing_secret_expires_at?->getTimestampMs(),
            ])->all(),
            'deliveries' => $deliveries->map(fn (WebhookDelivery $d) => [
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

    public function rolesAndPermissions(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $roles = Role::query()->withoutGlobalScopes()
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
            ])->all();

        $permissions = Permission::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('key')
            ->get()
            ->map(fn (Permission $p) => [
                'id' => $p->id,
                'key' => $p->key,
                'name' => $p->name,
                'description' => $p->description,
                'is_system' => (bool) $p->is_system,
            ])->all();

        return Inertia::render('Dashboard/RolesAndPermissions', [
            'roles' => $roles,
            'permissions' => $permissions,
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
}
