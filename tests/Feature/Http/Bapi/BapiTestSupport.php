<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Bapi;

use App\Models\ApiKey;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Boots a tenant + secret key, reloads routes against `api.authn.local`, and
 * returns the bearer token tests sign their requests with.
 */
final class BapiTestSupport
{
    public static function reloadRoutes(): void
    {
        $router = app('router');
        $router->setRoutes(new RouteCollection);

        $bapi = Route::middleware('bapi')->prefix('v1');
        $routingMode = (string) config('authn.routing_mode', 'subdomain');
        $bapiHost = (string) config('authn.bapi_host');
        if ($routingMode === 'subdomain') {
            $bapi->domain($bapiHost);
        } else {
            $bapi->prefix('api/v1');
        }
        $bapi->group(base_path('routes/bapi.php'));
    }

    public static function bootEnv(string $slug = 'acme'): array
    {
        config([
            'authn.routing_mode' => 'subdomain',
            'authn.app_host' => 'authn.local',
            'authn.bapi_host' => 'api.authn.local',
        ]);
        self::reloadRoutes();

        $project = Project::create(['name' => 'P-'.$slug, 'slug' => 'p-'.$slug]);
        $env = Environment::create([
            'project_id' => $project->id,
            'kind' => Environment::KIND_PRODUCTION,
            'slug' => $slug,
            'routing_label' => $slug,
            'allowed_origins' => [],
        ]);
        (new SigningKeyGenerator)->generate($env);

        $plaintext = 'sk_live_'.str_repeat($slug[0] ?? 'a', 32);
        $apiKey = ApiKey::create([
            'environment_id' => $env->id,
            'kind' => ApiKey::KIND_SECRET,
            'prefix' => 'sk_live_',
            'hashed_secret' => hash('sha256', $plaintext),
            'name' => 'test',
        ]);
        Cache::flush();

        return [
            'env' => $env,
            'api_key' => $apiKey,
            'token' => $plaintext,
        ];
    }

    public static function headers(string $token, array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer '.$token,
            'Host' => 'api.authn.local',
            'Accept' => 'application/json',
        ], $extra);
    }

    public static function url(string $path): string
    {
        return 'http://api.authn.local/v1'.$path;
    }
}
