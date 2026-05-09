<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Services\Keys\SigningKeyGenerator;
use App\Services\Sessions\SessionTokenIssuer;
use App\Services\Sessions\SessionTokenVerifier;
use Illuminate\Http\Request;


function tokenFixture(): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
    ]);

    $project = Project::create(['name' => 'Test', 'slug' => 'test']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
    ]);
    (new SigningKeyGenerator)->generate($env);

    $user = User::create(['environment_id' => $env->id]);
    $client = Client::create(['environment_id' => $env->id]);
    $session = Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);

    return ['env' => $env, 'session' => $session, 'user' => $user, 'client' => $client];
}

it('mints a JWT that verifies against the env JWKS', function (): void {
    $f = tokenFixture();
    $issuer = app(SessionTokenIssuer::class);
    $verifier = app(SessionTokenVerifier::class);

    $minted = $issuer->mint($f['session']);

    expect($minted['jwt'])->toBeString();
    expect($minted['expires_at'])->toBeInt();
    expect($minted['kid'])->toStartWith('kid_');

    $claims = $verifier->verify($minted['jwt'], $f['env']->fresh());
    expect($claims)->not->toBeNull();
    expect($claims['sub'])->toBe($f['user']->id);
    expect($claims['sid'])->toBe($f['session']->id);
    expect($claims['iss'])->toBe('https://acme.authn.local');
    expect($claims['v'])->toBe(2);
    expect($claims['sts'])->toBe('active');
});

it('honours azp from the request Origin', function (): void {
    $f = tokenFixture();
    $issuer = app(SessionTokenIssuer::class);
    $verifier = app(SessionTokenVerifier::class);

    $request = Request::create('https://acme.authn.local/v1/client/sessions/'.$f['session']->id.'/tokens', 'POST');
    $request->headers->set('Origin', 'https://app.example.com');

    $minted = $issuer->mint($f['session'], null, $request);
    $claims = $verifier->verify($minted['jwt'], $f['env']->fresh());

    expect($claims['azp'])->toBe('https://app.example.com');
});

it('honours per-environment lifetime override via appearance.sessions.lifetime_seconds', function (): void {
    $f = tokenFixture();
    $f['env']->forceFill([
        'appearance' => ['sessions' => ['lifetime_seconds' => 600]],
    ])->save();
    $f['env']->refresh();

    $issuer = app(SessionTokenIssuer::class);
    $minted = $issuer->mint($f['session']->fresh());

    expect($minted['expires_at'] - now()->getTimestamp())->toBeGreaterThan(595);
    expect($minted['expires_at'] - now()->getTimestamp())->toBeLessThanOrEqual(601);
});

it('throws template_not_found for any template name other than default', function (): void {
    $f = tokenFixture();
    $issuer = app(SessionTokenIssuer::class);

    expect(fn () => $issuer->mint($f['session'], 'supabase'))
        ->toThrow(InvalidArgumentException::class, 'template_not_found:supabase');
});

it('uses the latest active SigningKey for signing', function (): void {
    $f = tokenFixture();
    // Generate a second active key. The latest activated one should win.
    sleep(1);
    $newer = (new SigningKeyGenerator)->generate($f['env']->fresh());

    $issuer = app(SessionTokenIssuer::class);
    $minted = $issuer->mint($f['session']);

    expect($minted['kid'])->toBe($newer->id);
});

it('verifier rejects a tampered JWT', function (): void {
    $f = tokenFixture();
    $issuer = app(SessionTokenIssuer::class);
    $verifier = app(SessionTokenVerifier::class);

    $minted = $issuer->mint($f['session']);

    $segments = explode('.', $minted['jwt']);
    $tamperedHeader = base64_decode(strtr($segments[0], '-_', '+/'));
    $tamperedHeader = str_replace('"alg":"RS256"', '"alg":"none"', $tamperedHeader);
    $segments[0] = rtrim(strtr(base64_encode($tamperedHeader), '+/', '-_'), '=');
    $tampered = implode('.', $segments);

    expect($verifier->verify($tampered, $f['env']->fresh()))->toBeNull();
});

it('verifier rejects a token signed by a different env', function (): void {
    $f = tokenFixture();
    $issuer = app(SessionTokenIssuer::class);
    $verifier = app(SessionTokenVerifier::class);

    $project = Project::create(['name' => 'Other', 'slug' => 'other']);
    $other = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'other',
        'routing_label' => 'other',
    ]);
    (new SigningKeyGenerator)->generate($other);

    $minted = $issuer->mint($f['session']);

    expect($verifier->verify($minted['jwt'], $other->fresh()))->toBeNull();
});
