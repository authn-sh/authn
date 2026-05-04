<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function reloadFapiRoutesForEnvTest(): void
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

function envBoot(): Environment
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
    ]);
    reloadFapiRoutesForEnvTest();

    $project = Project::create(['name' => 'P', 'slug' => 'p']);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
    ]);
}

it('returns the canonical environment shape', function (): void {
    envBoot();
    $response = $this->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/environment');

    $response->assertOk();
    $response->assertJsonPath('object', 'environment');
    $response->assertJsonPath('auth_config.first_factors', ['password', 'email_code', 'reset_password_email_code', 'ticket']);
    $response->assertJsonPath('auth_config.identifiers', ['email_address']);
    $response->assertJsonPath('user_settings.attributes.email_address.required', true);
    $response->assertJsonPath('user_settings.attributes.password.required', true);
    $response->assertJsonPath('organization_settings.enabled', false);
    $response->assertJsonPath('commerce_settings.enabled', false);
    $response->assertJsonPath('localization.default_locale', 'en-US');
    $response->assertJsonPath('oauth_providers', []);
    $response->assertJsonPath('sessions.session_token_lifetime_seconds', 60);
});

it('responds with the public Cache-Control header', function (): void {
    envBoot();
    $response = $this->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/environment');

    expect($response->headers->get('Cache-Control'))->toContain('public');
    expect($response->headers->get('Cache-Control'))->toContain('max-age=60');
});

it('never returns secret captcha or signing material', function (): void {
    $env = envBoot();
    $env->forceFill([
        'appearance' => [
            'captcha' => [
                'provider' => 'turnstile',
                'widget_type' => 'invisible',
                'public_key' => 'public-key',
                'secret_key' => 'should-never-leak',
            ],
        ],
    ])->save();

    $response = $this->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/environment');

    expect($response->getContent())->not->toContain('should-never-leak');
    $response->assertJsonPath('captcha.public_key', 'public-key');
});

it('reflects appearance edits after the env is touched', function (): void {
    $env = envBoot();
    $first = $this->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/environment');
    expect($first->json('display_config.brand_color'))->toBeNull();

    $env->forceFill([
        'appearance' => ['brand_color' => '#ff0066'],
        'updated_at' => now()->addSeconds(2),
    ])->save();
    $env->refresh();
    // updated_at must change for the cache-key to invalidate.
    expect($env->updated_at->getTimestamp())->toBeGreaterThan(0);

    $second = $this->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/environment');
    expect($second->json('display_config.brand_color'))->toBe('#ff0066');
});
