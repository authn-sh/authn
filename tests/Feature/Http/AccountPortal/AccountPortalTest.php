<?php

declare(strict_types=1);

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

function reloadAccountPortalRoutes(): void
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

function bootAccountPortalEnv(array $appearance = ['paths' => []]): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
    ]);
    reloadAccountPortalRoutes();

    $project = Project::create(['name' => 'P', 'slug' => 'p']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => ['https://app.example.com'],
        'appearance' => array_merge(['application_name' => 'Acme'], $appearance),
        'home_url' => 'https://app.example.com',
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['env' => $env];
}

function makeAccountPortalSession(Environment $env): array
{
    $client = Client::create(['environment_id' => $env->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($client);
    $user = new User(['environment_id' => $env->id]);
    $user->save();
    EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'a@example.com',
        'verified_at' => now(),
        'is_primary' => true,
    ]);
    $session = Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
    ]);

    return ['client' => $client, 'cookie' => $cookie, 'user' => $user, 'session' => $session];
}

it('GET /sign-in returns the Inertia page with the bootstrap props', function (): void {
    $f = bootAccountPortalEnv();

    $r = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
        'Accept' => 'application/json',
    ])->getJson('https://acme.authn.local/sign-in');

    $r->assertOk()
        ->assertJsonPath('component', 'AccountPortal/SignIn')
        ->assertJsonPath('props.environment.fapi_url', 'https://acme.authn.local')
        ->assertJsonPath('props.environment.appearance.application_name', 'Acme');
    expect($r->json('props.environment.publishable_key'))->toStartWith('pk_live_');
});

it('GET /user signed-out redirects to /sign-in', function (): void {
    $f = bootAccountPortalEnv();

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->get('https://acme.authn.local/user');
    $r->assertRedirect('/sign-in');
});

it('GET /sign-in already-signed-in redirects to after_sign_in_url', function (): void {
    $f = bootAccountPortalEnv(['paths' => ['after_sign_in_url' => 'https://app.example.com/dashboard']]);
    $bs = makeAccountPortalSession($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->get('https://acme.authn.local/sign-in');

    $r->assertRedirect('https://app.example.com/dashboard');
});

it('POST /sign-out ends every live session and redirects', function (): void {
    $f = bootAccountPortalEnv(['paths' => ['after_sign_out_url' => 'https://app.example.com/goodbye']]);
    $bs = makeAccountPortalSession($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->post('https://acme.authn.local/sign-out');

    $r->assertRedirect('https://app.example.com/goodbye');
    expect(Session::query()->withoutGlobalScopes()->where('id', $bs['session']->id)->first()->status)
        ->toBe('ended');
});

it('GET /verify returns the verify page', function (): void {
    bootAccountPortalEnv();

    $r = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
        'Accept' => 'application/json',
    ])->getJson('https://acme.authn.local/verify');

    $r->assertOk()->assertJsonPath('component', 'AccountPortal/Verify');
});

it('bootstrap props snapshot — guards SDK contract against drift', function (): void {
    bootAccountPortalEnv();

    $r = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
        'Accept' => 'application/json',
    ])->getJson('https://acme.authn.local/sign-in');

    $env = $r->json('props.environment');
    expect(array_keys($env))->toBe([
        'publishable_key',
        'fapi_url',
        'appearance',
        'localization',
        'paths',
    ]);
    expect(array_keys($env['localization']))->toContain(
        'default_locale',
        'supported_locales',
        'fallback_locale',
    );
    expect(array_keys($env['paths']))->toContain(
        'sign_in_url',
        'sign_up_url',
        'after_sign_in_url',
        'after_sign_up_url',
        'after_sign_out_url',
        'user_profile_url',
    );
});
