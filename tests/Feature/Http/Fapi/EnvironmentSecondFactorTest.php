<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

function reloadFapiRoutesForSecondFactorTest(): void
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

function bootEnvForSecondFactorTest(string $slug, ?array $multiFactor): Environment
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.bapi_host' => 'api.authn.local',
    ]);
    reloadFapiRoutesForSecondFactorTest();
    Cache::flush();

    $project = Project::create(['name' => 'P-'.$slug, 'slug' => 'p-'.$slug]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);

    if ($multiFactor !== null) {
        $env->forceFill([
            'user_settings' => array_merge((array) $env->user_settings, ['multi_factor' => $multiFactor]),
        ])->save();
    }

    return $env;
}

it('returns auth_config.second_factors as [totp, backup_code] under spec defaults', function (): void {
    bootEnvForSecondFactorTest('mfa-defaults', null);

    $this->withHeader('Host', 'mfa-defaults.authn.local')
        ->getJson('https://mfa-defaults.authn.local/v1/environment')
        ->assertOk()
        ->assertJsonPath('auth_config.second_factors', ['totp', 'backup_code']);
});

it('omits totp from second_factors when multi_factor.totp.enabled is false', function (): void {
    bootEnvForSecondFactorTest('mfa-no-totp', [
        'totp' => ['enabled' => false],
        'backup_codes' => ['enabled' => true, 'default_count' => 10],
    ]);

    $this->withHeader('Host', 'mfa-no-totp.authn.local')
        ->getJson('https://mfa-no-totp.authn.local/v1/environment')
        ->assertOk()
        ->assertJsonPath('auth_config.second_factors', ['backup_code']);
});

it('omits backup_code from second_factors when multi_factor.backup_codes.enabled is false', function (): void {
    bootEnvForSecondFactorTest('mfa-no-bc', [
        'totp' => ['enabled' => true],
        'backup_codes' => ['enabled' => false, 'default_count' => 10],
    ]);

    $this->withHeader('Host', 'mfa-no-bc.authn.local')
        ->getJson('https://mfa-no-bc.authn.local/v1/environment')
        ->assertOk()
        ->assertJsonPath('auth_config.second_factors', ['totp']);
});

it('returns auth_config.second_factors as [] when both strategies are disabled', function (): void {
    bootEnvForSecondFactorTest('mfa-off', [
        'totp' => ['enabled' => false],
        'backup_codes' => ['enabled' => false, 'default_count' => 10],
    ]);

    $this->withHeader('Host', 'mfa-off.authn.local')
        ->getJson('https://mfa-off.authn.local/v1/environment')
        ->assertOk()
        ->assertJsonPath('auth_config.second_factors', []);
});
