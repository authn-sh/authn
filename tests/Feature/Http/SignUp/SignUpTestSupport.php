<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SignUp;

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Client\ClientResolver;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

/**
 * Shared fixtures for the SignUp HTTP tests. Mirrors SignInTestSupport
 * but creates an env tuned for sign-up: open signup_mode by default plus
 * the user_settings overrides each test passes in.
 */
final class SignUpTestSupport
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

    public static function bootEnv(array $userSettings = [], string $signupMode = 'public', string $allowedOrigin = 'https://app.example.com'): array
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
            'signup_mode' => $signupMode,
        ]);
        (new SigningKeyGenerator)->generate($env);

        return ['env' => $env, 'origin' => $allowedOrigin];
    }

    public static function clientWithCookie(Environment $env): array
    {
        $client = Client::create(['environment_id' => $env->id]);
        $cookie = app(ClientResolver::class)->mintCookieValue($client);

        return ['client' => $client, 'cookie' => $cookie];
    }
}
