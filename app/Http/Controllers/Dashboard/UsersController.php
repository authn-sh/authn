<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\Invitation;
use App\Models\Session;
use App\Models\User;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class UsersController
{
    use ResolvesDashboardEnv;

    public function usersTab(Request $request, string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        return match ($tab) {
            'invitations' => $this->invitations($project_slug, $env_slug),
            'sessions' => $this->sessions($project_slug, $env_slug),
            default => $this->users($request, $project_slug, $env_slug),
        };
    }

    private function users(Request $request, string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
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

    private function sessions(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
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

    private function invitations(string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
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
}
