<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\OrganizationMembershipRequest;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class OrganizationsController
{
    use ResolvesDashboardEnv;

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
}
