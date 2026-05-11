<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

function reloadFapiRoutesForLocalization(): void
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

function bootFapiEnvForLocalization(string $slug = 'locp'): Environment
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'http',
        'authn.app_port_suffix' => '',
    ]);
    reloadFapiRoutesForLocalization();
    Cache::flush();

    $project = Project::create(['name' => 'P-'.$slug, 'slug' => 'p-'.$slug]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
        'allowed_origins' => [],
    ]);
    (new SigningKeyGenerator)->generate($env);
    app()->instance(Environment::class, $env);

    return $env;
}

it('returns the merged catalog with the canonical default + overrides', function (): void {
    $env = bootFapiEnvForLocalization('cata');
    $env->forceFill([
        'localization' => [
            'default_locale' => 'en-US',
            'fallback_locale' => 'en-US',
            'supported_locales' => ['en-US'],
            'overrides' => ['en-US' => ['signIn.start.title' => 'Welcome to Acme']],
        ],
    ])->save();

    $resp = $this->getJson('http://'.$env->frontend_api_host.'/v1/localization/en-US');

    $resp->assertOk();
    expect($resp->json('locale'))->toBe('en-US');
    $catalog = (array) $resp->json('catalog');
    expect($catalog['signIn.start.title'])->toBe('Welcome to Acme');
    expect($catalog['formButtonPrimary'])->toBe('Continue');
});

it('returns 304 when If-None-Match matches the override_etag', function (): void {
    $env = bootFapiEnvForLocalization('catb');
    $etag = '"'.$env->localization_override_etag.'"';

    $resp = $this->getJson(
        'http://'.$env->frontend_api_host.'/v1/localization/en-US',
        ['If-None-Match' => $etag],
    );

    $resp->assertStatus(304);
    expect($resp->headers->get('ETag'))->toBe($etag);
});

it('returns Cache-Control and ETag headers', function (): void {
    $env = bootFapiEnvForLocalization('catc');

    $resp = $this->getJson('http://'.$env->frontend_api_host.'/v1/localization/en-US');

    $resp->assertOk();
    expect($resp->headers->get('Cache-Control'))->toContain('max-age=300');
    expect($resp->headers->get('Cache-Control'))->toContain('stale-while-revalidate=3600');
    expect((string) $resp->headers->get('ETag'))->toStartWith('"sha256:');
});

it('404s when the locale is not bundled and not in supported_locales', function (): void {
    $env = bootFapiEnvForLocalization('catd');

    $resp = $this->getJson('http://'.$env->frontend_api_host.'/v1/localization/ja-JP');

    $resp->assertStatus(404);
});
