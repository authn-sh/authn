<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * In non-local environments only an authenticated operator with the
     * `view-horizon` ability can hit /horizon. Until the operator-auth layer
     * lands (AU-17), non-local environments deny everyone — Horizon is reachable
     * only when APP_ENV=local.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => false);
    }
}
