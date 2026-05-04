<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Me;

use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Services\Keys\SigningKeyGenerator;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

final class MeTestSupport
{
    public static function reloadRoutes(): void
    {
        $router = app('router');
        $router->setRoutes(new RouteCollection);

        $appHost = (string) config('authn.app_host');
        $fapi = Route::middleware('fapi');
        if ((string) config('authn.routing_mode') === 'subdomain') {
            $fapi->domain('{env_slug}.'.$appHost);
        } else {
            $fapi->prefix('{env_slug}');
        }
        $fapi->group(base_path('routes/fapi.php'));
    }

    public static function bootEnv(array $userSettings = [], string $allowedOrigin = 'https://app.example.com'): array
    {
        config([
            'authn.routing_mode' => 'subdomain',
            'authn.app_host' => 'authn.local',
            'authn.app_scheme' => 'https',
            'authn.app_port_suffix' => '',
            'authn.bapi_host' => 'api.authn.local',
            'authn.dashboard_host' => 'dashboard.authn.local',
        ]);
        self::reloadRoutes();

        $project = Project::create(['name' => 'P', 'slug' => 'p']);
        $env = Environment::create([
            'project_id' => $project->id,
            'kind' => Environment::KIND_PRODUCTION,
            'slug' => 'acme',
            'routing_label' => 'acme',
            'allowed_origins' => [$allowedOrigin],
            'user_settings' => $userSettings,
        ]);
        (new SigningKeyGenerator)->generate($env);

        return ['env' => $env, 'origin' => $allowedOrigin];
    }

    public static function makeAuthenticatedUser(Environment $env, ?array $sessionOverrides = null): array
    {
        $user = new User(['environment_id' => $env->id, 'first_name' => 'Alice']);
        $user->setPassword('super-secret-password');
        $user->save();

        $email = EmailAddress::create([
            'environment_id' => $env->id,
            'user_id' => $user->id,
            'email_address' => 'alice@example.com',
            'verified_at' => now(),
            'is_primary' => true,
        ]);

        $client = Client::create(['environment_id' => $env->id]);
        $session = Session::create(array_merge([
            'environment_id' => $env->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'status' => Session::STATUS_ACTIVE,
        ], $sessionOverrides ?? []));

        // The issuer reads $session->environment lazily via the BelongsTo; the
        // morph FK is environment_id, so just refreshing is enough.
        $minted = app(SessionTokenIssuer::class)->mint($session->fresh());

        return [
            'user' => $user->fresh(),
            'email' => $email,
            'client' => $client,
            'session' => $session->fresh(),
            'jwt' => $minted['jwt'],
        ];
    }
}
