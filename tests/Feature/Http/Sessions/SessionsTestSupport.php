<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Sessions;

use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Services\Client\ClientResolver;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

final class SessionsTestSupport
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
            'frontend_api_host' => 'acme.authn.local',
            'allowed_origins' => [$allowedOrigin],
            'user_settings' => $userSettings,
        ]);
        (new SigningKeyGenerator)->generate($env);

        return ['env' => $env, 'origin' => $allowedOrigin];
    }

    public static function makeUserWithSession(Environment $env, ?Client $client = null, string $email = 'alice@example.com'): array
    {
        $user = new User(['environment_id' => $env->id, 'first_name' => 'Alice']);
        $user->setPassword('super-secret-password');
        $user->save();

        EmailAddress::create([
            'environment_id' => $env->id,
            'user_id' => $user->id,
            'email_address' => $email,
            'verified_at' => now(),
            'is_primary' => true,
        ]);

        $client ??= Client::create(['environment_id' => $env->id]);
        $cookie = app(ClientResolver::class)->mintCookieValue($client);
        $session = Session::create([
            'environment_id' => $env->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'status' => Session::STATUS_ACTIVE,
        ]);

        return ['user' => $user, 'client' => $client, 'cookie' => $cookie, 'session' => $session];
    }
}
