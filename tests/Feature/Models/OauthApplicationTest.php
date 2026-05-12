<?php

declare(strict_types=1);

use App\Models\AuthorizationGrant;
use App\Models\Environment;
use App\Models\OauthApplication;
use App\Models\Project;
use App\Models\User;

function makeEnvForOauthApp(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints an oac_ prefixed id and an oac_pub_ prefixed client_id', function (): void {
    $env = makeEnvForOauthApp();

    $app = OauthApplication::factory()->create([
        'environment_id' => $env->id,
        'name' => 'My App',
    ]);

    expect($app->id)->toStartWith('oac_');
    expect($app->client_id)->toStartWith('oac_pub_');
    expect($app->callback_urls)->toBe(['https://example.test/oauth/callback']);
    expect($app->scopes)->toBe(['openid', 'profile', 'email']);
    expect($app->is_public)->toBeFalse();
});

it('mints a hashed client secret + verifies the plaintext + hides hashed_client_secret', function (): void {
    $env = makeEnvForOauthApp();
    $secret = OauthApplication::mintClientSecret();

    expect($secret['plaintext'])->toStartWith('oac_sec_');
    expect($secret['hash'])->toBe(hash('sha256', $secret['plaintext']));

    $app = OauthApplication::factory()->create([
        'environment_id' => $env->id,
        'hashed_client_secret' => $secret['hash'],
    ]);

    expect($app->verifyClientSecret($secret['plaintext']))->toBeTrue();
    expect($app->verifyClientSecret('wrong-secret'))->toBeFalse();
    expect(array_key_exists('hashed_client_secret', $app->toArray()))->toBeFalse();
});

it('treats public clients as having no secret', function (): void {
    $env = makeEnvForOauthApp();

    $app = OauthApplication::factory()->public()->create(['environment_id' => $env->id]);

    expect($app->is_public)->toBeTrue();
    expect($app->hashed_client_secret)->toBeNull();
    expect($app->verifyClientSecret('anything'))->toBeFalse();
});

it('soft-deletes via removed_at', function (): void {
    $env = makeEnvForOauthApp();
    app()->instance(Environment::class, $env);

    $app = OauthApplication::factory()->create(['environment_id' => $env->id]);
    $app->delete();

    expect(OauthApplication::query()->find($app->id))->toBeNull();
    expect(OauthApplication::withTrashed()->find($app->id)?->removed_at)->not->toBeNull();
    app()->forgetInstance(Environment::class);
});

it('honours EnvironmentScope', function (): void {
    $env1 = makeEnvForOauthApp('one');
    $env2 = makeEnvForOauthApp('two');

    $a1 = OauthApplication::factory()->create(['environment_id' => $env1->id, 'name' => 'a1']);
    OauthApplication::factory()->create(['environment_id' => $env2->id, 'name' => 'a2']);

    app()->instance(Environment::class, $env1);
    expect(OauthApplication::query()->pluck('id')->all())->toBe([$a1->id]);
    app()->forgetInstance(Environment::class);
});

it('exposes authorizationGrants relation', function (): void {
    $env = makeEnvForOauthApp();
    $user = User::create(['environment_id' => $env->id]);
    $app = OauthApplication::factory()->create(['environment_id' => $env->id]);

    AuthorizationGrant::factory()->create([
        'environment_id' => $env->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
    ]);

    expect($app->refresh()->authorizationGrants)->toHaveCount(1);
});
