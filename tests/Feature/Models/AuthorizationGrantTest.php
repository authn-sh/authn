<?php

declare(strict_types=1);

use App\Models\AuthorizationGrant;
use App\Models\Environment;
use App\Models\OauthApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;

function makeEnvForAuthGrant(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints an authgrant_ prefixed id, computes scopes_hash on create, and stamps granted_at', function (): void {
    $env = makeEnvForAuthGrant();
    $user = User::create(['environment_id' => $env->id]);
    $app = OauthApplication::factory()->create(['environment_id' => $env->id]);

    $grant = AuthorizationGrant::factory()->create([
        'environment_id' => $env->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
        'scopes' => ['openid', 'profile'],
        'scopes_hash' => '',
    ]);

    expect($grant->id)->toStartWith('authgrant_');
    expect($grant->scopes)->toBe(['openid', 'profile']);
    expect($grant->scopes_hash)->toBe(AuthorizationGrant::hashScopes(['openid', 'profile']));
    expect($grant->granted_at)->not->toBeNull();
    expect($grant->isActive())->toBeTrue();
});

it('produces an order-independent scopes_hash', function (): void {
    $h1 = AuthorizationGrant::hashScopes(['openid', 'profile', 'email']);
    $h2 = AuthorizationGrant::hashScopes(['email', 'openid', 'profile']);
    $h3 = AuthorizationGrant::hashScopes(['openid', 'profile']);

    expect($h1)->toBe($h2);
    expect($h1)->not->toBe($h3);
});

it('revokes an active grant and is filtered out of the active scope', function (): void {
    $env = makeEnvForAuthGrant();
    app()->instance(Environment::class, $env);
    $user = User::create(['environment_id' => $env->id]);
    $app = OauthApplication::factory()->create(['environment_id' => $env->id]);

    $grant = AuthorizationGrant::factory()->create([
        'environment_id' => $env->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
    ]);

    $grant->revoke();
    expect($grant->fresh()->isActive())->toBeFalse();
    expect(AuthorizationGrant::query()->active()->pluck('id')->all())->toBe([]);
    expect(AuthorizationGrant::query()->revoked()->pluck('id')->all())->toBe([$grant->id]);
    app()->forgetInstance(Environment::class);
});

it('refuses a duplicate active (user, app, scopes_hash) row', function (): void {
    $env = makeEnvForAuthGrant();
    $user = User::create(['environment_id' => $env->id]);
    $app = OauthApplication::factory()->create(['environment_id' => $env->id]);

    AuthorizationGrant::factory()->create([
        'environment_id' => $env->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
        'scopes' => ['openid', 'profile'],
    ]);

    expect(fn () => AuthorizationGrant::factory()->create([
        'environment_id' => $env->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
        'scopes' => ['openid', 'profile'],
    ]))->toThrow(QueryException::class);
});

it('allows re-granting once an old grant has been revoked', function (): void {
    $env = makeEnvForAuthGrant();
    $user = User::create(['environment_id' => $env->id]);
    $app = OauthApplication::factory()->create(['environment_id' => $env->id]);

    $first = AuthorizationGrant::factory()->create([
        'environment_id' => $env->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
        'scopes' => ['openid', 'profile'],
    ]);
    $first->revoke();

    $second = AuthorizationGrant::factory()->create([
        'environment_id' => $env->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
        'scopes' => ['openid', 'profile'],
    ]);

    expect($second->id)->not->toBe($first->id);
    expect($second->isActive())->toBeTrue();
})->skip(DB::connection()->getDriverName() !== 'pgsql', 'partial unique index only enforced on pgsql');

it('exposes oauthApplication + user relations', function (): void {
    $env = makeEnvForAuthGrant();
    $user = User::create(['environment_id' => $env->id]);
    $app = OauthApplication::factory()->create(['environment_id' => $env->id]);

    $grant = AuthorizationGrant::factory()->create([
        'environment_id' => $env->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
    ]);

    expect($grant->oauthApplication->id)->toBe($app->id);
    expect($grant->user->id)->toBe($user->id);
});
