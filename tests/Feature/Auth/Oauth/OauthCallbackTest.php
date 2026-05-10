<?php

declare(strict_types=1);

use App\Auth\Oauth\StateToken;
use App\Models\Client;
use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\OauthProvider;
use App\Models\Project;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

function reloadOauthFapiRoutes(): void
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

function envForOauthCallback(): Environment
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'http',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
    ]);
    reloadOauthFapiRoutesIfNeeded();
    $project = Project::create(['name' => 'P', 'slug' => 'p-cb']);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'cb',
        'routing_label' => 'cb',
    ]);
}

function reloadOauthFapiRoutesIfNeeded(): void
{
    reloadOauthFapiRoutes();
}

function makeEnabledGoogleProvider(Environment $env): OauthProvider
{
    $row = OauthProvider::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('provider_key', 'google')
        ->firstOrFail();
    $row->forceFill([
        'enabled' => true,
        'client_id' => 'gid',
        'encrypted_client_secret' => 'gsecret',
    ])->save();

    return $row->refresh();
}

it('callback rejects invalid state with a 302 + __authn_error', function (): void {
    $env = envForOauthCallback();

    $r = $this->withHeaders(['Host' => 'cb.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('http://cb.authn.local/v1/oauth-callback/google?state=garbage&code=abc');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('__authn_error=state_invalid');
});

it('callback succeeds: exchanges code, fetches userinfo, creates User + ExternalAccount', function (): void {
    $env = envForOauthCallback();
    app()->instance(Environment::class, $env);
    $provider = makeEnabledGoogleProvider($env);
    $client = Client::create(['environment_id' => $env->id]);
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => 'oauth_google',
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600, 'scope' => 'openid email profile',
        ], 200),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response([
            'sub' => 'g-12345', 'email' => 'alice@example.com', 'email_verified' => true,
            'given_name' => 'Alice', 'family_name' => 'Smith', 'picture' => 'https://example.com/a.png',
        ], 200),
    ]);

    $state = StateToken::mint(
        environmentId: $env->id,
        providerKey: 'google',
        verificationId: $verification->id,
        clientId: $client->id,
        attemptId: $attempt->id,
        attemptKind: 'sign_in',
        redirectUrl: 'http://app.example.com/sign-in',
        redirectUrlComplete: 'http://app.example.com/dashboard',
        nonce: 'n-1',
    );

    $r = $this->withHeaders(['Host' => 'cb.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('http://cb.authn.local/v1/oauth-callback/google?state='.urlencode($state).'&code=auth-code-1');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toBe('http://app.example.com/dashboard');

    expect($verification->fresh()->status)->toBe(Verification::STATUS_VERIFIED);

    $ext = ExternalAccount::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('oauth_provider_id', $provider->id)
        ->where('provider_user_id', 'g-12345')
        ->first();
    expect($ext)->not->toBeNull();
    $user = User::query()->withoutGlobalScopes()->where('id', $ext->user_id)->first();
    expect($user)->not->toBeNull();
    expect($user->first_name)->toBe('Alice');
});

it('callback handles IdP error by flipping verification + redirecting with the error', function (): void {
    $env = envForOauthCallback();
    app()->instance(Environment::class, $env);
    makeEnabledGoogleProvider($env);
    $client = Client::create(['environment_id' => $env->id]);
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => 'oauth_google',
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addMinutes(10),
    ]);
    $state = StateToken::mint(
        environmentId: $env->id,
        providerKey: 'google',
        verificationId: $verification->id,
        clientId: $client->id,
        attemptId: $attempt->id,
        attemptKind: 'sign_in',
        redirectUrl: 'http://app.example.com/sign-in',
        redirectUrlComplete: 'http://app.example.com/dashboard',
        nonce: 'n-1',
    );

    $r = $this->withHeaders(['Host' => 'cb.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('http://cb.authn.local/v1/oauth-callback/google?state='.urlencode($state).'&error=access_denied&error_description=denied');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('__authn_error=access_denied');
    expect($verification->fresh()->status)->toBe(Verification::STATUS_FAILED);
    expect($verification->fresh()->error_code)->toBe('access_denied');
});

it('callback existing-user re-link updates tokens without creating a duplicate ExternalAccount', function (): void {
    $env = envForOauthCallback();
    app()->instance(Environment::class, $env);
    $provider = makeEnabledGoogleProvider($env);
    $user = User::query()->withoutGlobalScopes()->create(['environment_id' => $env->id, 'first_name' => 'Existing']);
    ExternalAccount::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'g-existing',
        'encrypted_access_token' => 'old-at',
        'linked_at' => now(),
    ]);
    $client = Client::create(['environment_id' => $env->id]);
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => 'oauth_google',
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addMinutes(10),
    ]);
    $state = StateToken::mint(
        environmentId: $env->id,
        providerKey: 'google',
        verificationId: $verification->id,
        clientId: $client->id,
        attemptId: $attempt->id,
        attemptKind: 'sign_in',
        redirectUrl: 'http://app.example.com/',
        redirectUrlComplete: 'http://app.example.com/done',
        nonce: 'n-1',
    );

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-at', 'refresh_token' => 'fresh-rt', 'expires_in' => 3600], 200),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'g-existing', 'email' => 'existing@example.com', 'email_verified' => true], 200),
    ]);

    $r = $this->withHeaders(['Host' => 'cb.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('http://cb.authn.local/v1/oauth-callback/google?state='.urlencode($state).'&code=ok');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toBe('http://app.example.com/done');

    $rows = ExternalAccount::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('oauth_provider_id', $provider->id)
        ->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->encrypted_access_token)->toBe('fresh-at');
});
