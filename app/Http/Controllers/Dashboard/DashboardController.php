<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\AllowlistIdentifier;
use App\Models\ApiKey;
use App\Models\BlocklistIdentifier;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\Invitation;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Keys\KeyGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            return redirect('/create-workspace');
        }
        $project = $this->firstProjectOutsideAdmin();
        if ($project === null) {
            return redirect('/create-project');
        }
        $env = $project->environments()->where('kind', Environment::KIND_PRODUCTION)->first()
            ?? $project->environments()->first();

        return redirect("/{$project->slug}/".($env?->slug ?? 'production').'/overview');
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
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9-]{3,40}$/', 'not_in:_admin'],
        ]);
        $workspace = $this->workspace();
        if ($workspace === null) {
            return redirect('/create-workspace');
        }

        $project = Project::query()->withoutGlobalScopes()->create([
            'name' => (string) $request->input('name'),
            'slug' => (string) $request->input('slug'),
            'owner_organization_id' => $workspace->organization_id,
        ]);
        $env = Environment::query()->withoutGlobalScopes()->create([
            'project_id' => $project->id,
            'kind' => Environment::KIND_PRODUCTION,
            'slug' => 'production',
            'frontend_api_host' => $project->slug.'.'.config('authn.app_host', 'authn.local'),
            'allowed_origins' => [],
        ]);
        ApiKey::query()->create([
            'environment_id' => $env->id,
            'kind' => ApiKey::KIND_PUBLISHABLE,
            'prefix' => 'pk_'.$env->keyEnvironmentSegment().'_',
            'hashed_secret' => $this->keyGenerator->hash($this->keyGenerator->publishableKey($env)),
            'name' => 'Default publishable key',
        ]);

        return redirect("/{$project->slug}/{$env->slug}/overview");
    }

    public function overview(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect('/');
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
            return redirect('/');
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
            return redirect('/');
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
            return redirect('/');
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
            return redirect('/');
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
            return redirect('/');
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
            return redirect('/');
        }

        return Inertia::render('Dashboard/Configure', [
            'section' => $section,
            'user_settings' => is_array($env->user_settings) ? $env->user_settings : [],
            'allowed_origins' => is_array($env->allowed_origins) ? $env->allowed_origins : [],
            'appearance' => is_array($env->appearance) ? $env->appearance : [],
            'signup_mode' => $env->signup_mode,
        ]);
    }

    public function emailTemplates(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect('/');
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
            return redirect('/');
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
            return redirect('/');
        }
        $existing = ApiKey::query()->where('environment_id', $env->id)->where('id', $id)->first();
        if ($existing === null) {
            return redirect("/{$project_slug}/{$env_slug}/api-keys");
        }

        $plaintext = $existing->kind === ApiKey::KIND_SECRET
            ? $this->keyGenerator->secretKey($env)
            : $this->keyGenerator->publishableKey($env);
        $existing->forceFill([
            'prefix' => substr($plaintext, 0, 16),
            'hashed_secret' => $this->keyGenerator->hash($plaintext),
            'last_used_at' => null,
        ])->save();

        return redirect("/{$project_slug}/{$env_slug}/api-keys")
            ->with('rotated_secret', $plaintext);
    }

    public function webhooks(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect('/');
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
            return redirect('/');
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

        return redirect("/{$project_slug}/{$env_slug}/webhooks")
            ->with('signing_secret', $row->displaySecret());
    }

    public function auditLog(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect('/');
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
            return redirect('/create-workspace');
        }

        return Inertia::render('Dashboard/WorkspaceSettings', [
            'membership' => [
                'organization_id' => $workspace->organization_id,
                'role' => $workspace->role,
            ],
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
