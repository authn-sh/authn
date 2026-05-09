<?php

namespace App\Providers;

use App\Models\Environment;
use App\Models\Organization;
use App\Models\User;
use App\Observers\EnvironmentObserver;
use App\Services\Tenancy\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Environment::observe(EnvironmentObserver::class);

        // Policy-style entry points for org-scoped permission checks.
        // Controllers in AU-3+ can write
        //   $this->authorize('org:sys_memberships:manage', $organization)
        // and the abilities listed below dispatch through to
        // User::hasOrgPermission().
        foreach (RoleSeeder::SYSTEM_PERMISSIONS as $row) {
            Gate::define(
                $row['key'],
                fn (User $user, Organization $org): bool => $user->hasOrgPermission($row['key'], $org),
            );
        }
    }
}
