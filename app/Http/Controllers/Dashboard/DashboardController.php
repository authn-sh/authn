<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Jobs\Sms\SendSmsTemplate;
use App\Models\AllowlistIdentifier;
use App\Models\ApiKey;
use App\Models\BlocklistIdentifier;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\Invitation;
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
use App\Services\Keys\KeyGenerator;
use App\Settings\MultiFactorSettings;
use App\Support\RoutingLabel;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

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
    public function __construct(private readonly KeyGenerator $keyGenerator) {}

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
        ]);
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

    public function auditLog(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        return Inertia::render('Dashboard/AuditLog', [
            'note' => 'Full audit log lands in v0.8 (PLAN §15.5). v0.1 surfaces only API key + webhook endpoint mutations.',
            'entries' => [],
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
