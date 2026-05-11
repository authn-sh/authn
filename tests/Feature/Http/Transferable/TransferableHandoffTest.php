<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use App\Models\SignInAttempt;
use App\Models\SignUpAttempt;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Http\SignIn\SignInTestSupport;
use Tests\Feature\Http\SignUp\SignUpTestSupport;

function bootEnvForHandoff(string $signupMode = Environment::SIGNUP_MODE_PUBLIC): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
    ]);

    $router = app('router');
    $router->setRoutes(new RouteCollection);
    Route::middleware('fapi')->domain('{env_slug}.authn.local')->group(base_path('routes/fapi.php'));

    $project = Project::create(['name' => 'P', 'slug' => 'p-'.uniqid()]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => ['https://app.example.com'],
        'signup_mode' => $signupMode,
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['env' => $env, 'origin' => 'https://app.example.com'];
}

it('flips SignUp to transferable when the identifier already belongs to a User', function (): void {
    $f = bootEnvForHandoff();
    SignInTestSupport::makeUser($f['env'], 'alice@example.com', 'super-secret-password');
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ups', [
            'email_address' => 'alice@example.com',
        ]);

    $create->assertOk()
        ->assertJsonPath('response.status', SignUpAttempt::STATUS_TRANSFERABLE)
        ->assertJsonPath('response.transferable_to_signin', true)
        ->assertJsonPath('response.target_flow', 'sign_in')
        ->assertJsonPath('response.email_address', 'alice@example.com');
});

it('flips SignIn to transferable when the identifier is unknown and signup is public', function (): void {
    $f = bootEnvForHandoff(Environment::SIGNUP_MODE_PUBLIC);
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'newbie@example.com',
        ]);

    $create->assertOk()
        ->assertJsonPath('response.status', SignInAttempt::STATUS_TRANSFERABLE)
        ->assertJsonPath('response.transferable_to_signup', true)
        ->assertJsonPath('response.target_flow', 'sign_up');
});

it('flips SignIn to transferable when the identifier is unknown and signup is restricted', function (): void {
    $f = bootEnvForHandoff(Environment::SIGNUP_MODE_RESTRICTED);
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'newbie@example.com',
        ]);

    $create->assertOk()
        ->assertJsonPath('response.status', SignInAttempt::STATUS_TRANSFERABLE)
        ->assertJsonPath('response.transferable_to_signup', true);
});

it('keeps SignIn in needs_first_factor when the identifier matches a known user', function (): void {
    $f = bootEnvForHandoff(Environment::SIGNUP_MODE_PUBLIC);
    SignInTestSupport::makeUser($f['env'], 'alice@example.com', 'super-secret-password');
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);

    $create->assertOk()
        ->assertJsonPath('response.status', SignInAttempt::STATUS_NEEDS_FIRST_FACTOR)
        ->assertJsonPath('response.transferable_to_signup', false)
        ->assertJsonPath('response.target_flow', null);
});

it('does not expose transferable_to_signin on a normal in-progress SignUp', function (): void {
    $f = bootEnvForHandoff();
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ups', [
            'email_address' => 'newbie@example.com',
        ]);

    $create->assertOk()
        ->assertJsonPath('response.transferable_to_signin', false)
        ->assertJsonPath('response.target_flow', null);
});
