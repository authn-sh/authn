<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SignIn;

use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Services\Client\ClientResolver;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

/**
 * Shared fixtures for the SignIn HTTP tests. Uses a per-call random
 * suffix so multiple `signInBoot()` calls in the same test don't trip
 * the env_slug unique constraint.
 */
final class SignInTestSupport
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

    public static function bootEnv(string $allowedOrigin = 'https://app.example.com'): array
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
        ]);
        (new SigningKeyGenerator)->generate($env);

        return ['env' => $env, 'origin' => $allowedOrigin];
    }

    public static function makeUser(Environment $env, string $email = 'alice@example.com', ?string $password = 'super-secret-password'): array
    {
        $user = new User(['environment_id' => $env->id, 'first_name' => 'Alice', 'last_name' => 'Smith']);
        if ($password !== null) {
            $user->setPassword($password);
        }
        $user->save();

        $emailRow = EmailAddress::create([
            'environment_id' => $env->id,
            'user_id' => $user->id,
            'email_address' => $email,
            'verified_at' => now(),
            'is_primary' => true,
        ]);

        return ['user' => $user->fresh(), 'email' => $emailRow];
    }

    public static function clientWithCookie(Environment $env): array
    {
        $client = Client::create(['environment_id' => $env->id]);
        $cookie = app(ClientResolver::class)->mintCookieValue($client);

        return ['client' => $client, 'cookie' => $cookie];
    }
}
