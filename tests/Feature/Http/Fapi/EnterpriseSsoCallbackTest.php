<?php

declare(strict_types=1);

use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

function bootEnvForEnterpriseSsoCallback(): array
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
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['env' => $env];
}

it('returns a redirect with __authn_error=state_invalid when the OIDC state is bogus', function (): void {
    bootEnvForEnterpriseSsoCallback();

    $r = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->get('https://acme.authn.local/v1/enterprise-sso-callback?state=tampered&code=anything');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('__authn_error=state_invalid');
});

it('returns a redirect with __authn_error=state_invalid when the SAML RelayState is missing', function (): void {
    $f = bootEnvForEnterpriseSsoCallback();
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);

    $r = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->post("https://acme.authn.local/v1/saml/{$conn->id}/acs", [
            'SAMLResponse' => 'not-a-real-response',
        ]);

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('__authn_error=state_invalid');
});

it('returns enterprise_connection_not_found when the SAML ACS connection id is unknown', function (): void {
    bootEnvForEnterpriseSsoCallback();

    $r = $this->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local'])
        ->post('https://acme.authn.local/v1/saml/entcon_unknown/acs', [
            'SAMLResponse' => 'x',
            'RelayState' => 'y',
        ]);

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('__authn_error=enterprise_connection_not_found');
});
