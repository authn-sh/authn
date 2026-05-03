<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Services\Client\ClientResolver;
use App\Services\Keys\SigningKeyGenerator;
use App\Services\Sessions\HandshakeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function reloadFapiRoutesForClientTest(): void
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

function clientBoot(): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
    ]);
    reloadFapiRoutesForClientTest();

    $project = Project::create(['name' => 'P', 'slug' => 'p']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'frontend_api_host' => 'acme.authn.local',
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['env' => $env];
}

it('mints a fresh client + sets the cookie when no __client cookie is present', function (): void {
    clientBoot();

    $response = $this->withCredentials()
        ->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/client');

    $response->assertOk()
        ->assertJsonPath('object', 'client')
        ->assertJsonPath('sessions', [])
        ->assertJsonPath('sign_in', null)
        ->assertJsonPath('sign_up', null);

    $cookieValues = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
    expect($cookieValues->has('__client'))->toBeTrue();

    expect(Client::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('returns the existing client when a valid cookie is supplied', function (): void {
    $f = clientBoot();
    $client = Client::create(['environment_id' => $f['env']->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($client);

    $response = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/client');

    $response->assertOk()->assertJsonPath('id', $client->id);
    expect(Client::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('mints a fresh client when the cookie is tampered (does not throw)', function (): void {
    $f = clientBoot();

    $response = $this->withCredentials()
        ->withUnencryptedCookie('__client', 'forged.invalid')
        ->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/client');

    $response->assertOk();
    expect(Client::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('PUT /v1/client always mints a fresh row', function (): void {
    $f = clientBoot();
    Client::create(['environment_id' => $f['env']->id]);

    $response = $this->withCredentials()
        ->withHeader('Host', 'acme.authn.local')
        ->putJson('https://acme.authn.local/v1/client');

    $response->assertOk();
    expect(Client::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('DELETE /v1/client ends every active session and clears the cookie', function (): void {
    $f = clientBoot();
    $client = Client::create(['environment_id' => $f['env']->id]);
    $user = User::create(['environment_id' => $f['env']->id]);
    Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);

    $cookie = app(ClientResolver::class)->mintCookieValue($client);

    $response = $this->withCredentials()
        ->withUnencryptedCookie('__client', $cookie)
        ->withHeader('Host', 'acme.authn.local')
        ->deleteJson('https://acme.authn.local/v1/client');

    $response->assertOk()->assertJson(['deleted' => true, 'client' => null]);
    expect(Session::query()->withoutGlobalScopes()->where('client_id', $client->id)->first()->status)->toBe('ended');

    $cleared = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === '__client');
    // Cookie::forget produces an empty/null value with an expiry in the past.
    expect($cleared)->not->toBeNull();
    expect((string) $cleared->getValue())->toBe('');
    expect($cleared->getExpiresTime())->toBeLessThan(now()->getTimestamp());
});

it('handshake redeems a valid token and sets the cookie', function (): void {
    $f = clientBoot();
    $client = Client::create(['environment_id' => $f['env']->id]);
    $token = app(HandshakeToken::class)->issue($f['env'], $client);

    $response = $this->withCredentials()
        ->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/client/handshake?handshake_token='.urlencode($token));

    $response->assertOk()->assertJsonPath('id', $client->id);
    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
    expect($cookies->has('__client'))->toBeTrue();
});

it('handshake refuses replay (single-use jti)', function (): void {
    $f = clientBoot();
    $client = Client::create(['environment_id' => $f['env']->id]);
    $token = app(HandshakeToken::class)->issue($f['env'], $client);

    $url = 'https://acme.authn.local/v1/client/handshake?handshake_token='.urlencode($token);

    $this->withCredentials()->withHeader('Host', 'acme.authn.local')->getJson($url)->assertOk();

    $this->withCredentials()->withHeader('Host', 'acme.authn.local')->getJson($url)
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'handshake_token_invalid');
});

it('handshake refuses a malformed token', function (): void {
    clientBoot();

    $this->withCredentials()->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/client/handshake?handshake_token=not-a-jwt')
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'handshake_token_invalid');
});

it('handshake refuses a token issued for a different env', function (): void {
    $f = clientBoot();
    $client = Client::create(['environment_id' => $f['env']->id]);
    $token = app(HandshakeToken::class)->issue($f['env'], $client);

    $project2 = Project::create(['name' => 'Other', 'slug' => 'other']);
    $other = Environment::create([
        'project_id' => $project2->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'other',
        'frontend_api_host' => 'other.authn.local',
    ]);
    (new SigningKeyGenerator)->generate($other);

    $this->withCredentials()->withHeader('Host', 'other.authn.local')
        ->getJson('https://other.authn.local/v1/client/handshake?handshake_token='.urlencode($token))
        ->assertUnauthorized();
});

it('handshake refuses a request with no token', function (): void {
    clientBoot();

    $this->withCredentials()->withHeader('Host', 'acme.authn.local')
        ->getJson('https://acme.authn.local/v1/client/handshake')
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'handshake_token_missing');
});
