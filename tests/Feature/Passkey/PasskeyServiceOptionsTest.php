<?php

declare(strict_types=1);

use App\Auth\Passkey\PasskeyService;
use App\Auth\Passkey\RpConfigResolver;
use App\Models\Challenge;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Passkey;
use App\Models\Project;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Support\Base64Url;

function makeEnvForPasskeyService(string $slug = 'env'): Environment
{
    config([
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.routing_mode' => 'subdomain',
        'authn.app_port_suffix' => '',
    ]);
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

function makeChallengeForPasskey(Environment $env, User $user): Challenge
{
    $verification = Verification::create([
        'environment_id' => $env->id,
        'verifiable_type' => 'user',
        'verifiable_id' => $user->id,
        'strategy' => Verification::STRATEGY_PASSKEY,
        'status' => Verification::STATUS_UNVERIFIED,
        'expire_at' => now()->addMinutes(10),
    ]);
    $client = Client::create(['environment_id' => $env->id]);
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'status' => 'pending',
        'identifier' => 'x@y.com',
    ]);

    return Challenge::create([
        'environment_id' => $env->id,
        'parent_type' => Challenge::PARENT_SIGN_IN,
        'parent_id' => $attempt->id,
        'step' => Challenge::STEP_FIRST,
        'strategy' => Verification::STRATEGY_PASSKEY,
        'status' => Challenge::STATUS_PENDING,
        'verification_id' => $verification->id,
        'expire_at' => now()->addMinutes(10),
    ]);
}

it('buildRegistrationOptions writes the base64url challenge nonce onto the Challenge', function (): void {
    $env = makeEnvForPasskeyService('reg1');
    app()->instance(Environment::class, $env);
    $user = User::create(['environment_id' => $env->id, 'username' => 'alice']);
    $challenge = makeChallengeForPasskey($env, $user);

    expect($challenge->nonce)->toBeNull();

    $options = app(PasskeyService::class)->buildRegistrationOptions($env, $user, $challenge);

    $challenge->refresh();
    expect($challenge->nonce)->not->toBeNull();
    expect($options)->toHaveKey('rp');
    expect($options)->toHaveKey('user');
    expect($options)->toHaveKey('challenge');
    expect($options['rp']['id'])->toBe('reg1.authn.local');
    expect($options['user']['id'])->toBe(Base64Url::encode($user->id));
    // The nonce must decode to the same challenge bytes that the SDK
    // received in `options.challenge` — verifying the round-trip is the
    // load-bearing assertion here.
    expect(Base64Url::decode((string) $challenge->nonce))
        ->toBe(Base64Url::decode((string) $options['challenge']));
});

it('buildAuthenticationOptions narrows allowCredentials to the user verified passkeys', function (): void {
    $env = makeEnvForPasskeyService('auth1');
    app()->instance(Environment::class, $env);
    $user = User::create(['environment_id' => $env->id, 'username' => 'bob']);
    $challenge = makeChallengeForPasskey($env, $user);

    $verified = Passkey::factory()->create([
        'user_id' => $user->id,
        'transports' => [Passkey::TRANSPORT_INTERNAL],
        'verified_at' => now(),
    ]);
    Passkey::factory()->unverified()->create(['user_id' => $user->id]);

    $options = app(PasskeyService::class)->buildAuthenticationOptions($env, $challenge, $user);

    expect($options)->toHaveKey('challenge');
    expect($options['rpId'])->toBe('auth1.authn.local');
    expect($options['allowCredentials'])->toHaveCount(1);
    expect($options['allowCredentials'][0]['id'])->toBe(Base64Url::encode($verified->credential_id));
});

it('buildAuthenticationOptions returns an empty allowCredentials list when no user is bound', function (): void {
    $env = makeEnvForPasskeyService('auth2');
    app()->instance(Environment::class, $env);
    $user = User::create(['environment_id' => $env->id]);
    $challenge = makeChallengeForPasskey($env, $user);

    $options = app(PasskeyService::class)->buildAuthenticationOptions($env, $challenge, null);

    expect($options['allowCredentials'] ?? [])->toBe([]);
});

it('the resolver and the service share the same env-derived RP id', function (): void {
    $env = makeEnvForPasskeyService('share');
    app()->instance(Environment::class, $env);
    $user = User::create(['environment_id' => $env->id]);
    $challenge = makeChallengeForPasskey($env, $user);

    $rpId = (new RpConfigResolver)->rpId($env);
    $options = app(PasskeyService::class)->buildRegistrationOptions($env, $user, $challenge);

    expect($options['rp']['id'])->toBe($rpId);
});
