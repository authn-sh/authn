<?php

declare(strict_types=1);

use App\Models\AuthorizationGrant;
use App\Models\OauthApplication;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;

function meAuthorizedAppsReq(string $method, string $path, string $jwt): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}");
}

it('lists active AuthorizationGrants for the authenticated user, freshest first', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    $appA = OauthApplication::factory()->create(['environment_id' => $f['env']->id, 'name' => 'App A']);
    $appB = OauthApplication::factory()->create(['environment_id' => $f['env']->id, 'name' => 'App B']);
    $older = AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $appA->id,
        'user_id' => $auth['user']->id,
        'granted_at' => now()->subHour(),
    ]);
    $newer = AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $appB->id,
        'user_id' => $auth['user']->id,
        'granted_at' => now(),
    ]);

    $r = meAuthorizedAppsReq('GET', '/me/authorized-apps', $auth['jwt']);

    $r->assertOk()->assertJsonPath('total_count', 2)
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.oauth_application_name', 'App B')
        ->assertJsonPath('data.1.id', $older->id);
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('omits revoked grants from the list', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    AuthorizationGrant::factory()->revoked()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $auth['user']->id,
    ]);

    $r = meAuthorizedAppsReq('GET', '/me/authorized-apps', $auth['jwt']);

    $r->assertOk()->assertJsonPath('total_count', 0);
});

it('omits grants belonging to a different user', function (): void {
    $f = MeTestSupport::bootEnv();
    $me = MeTestSupport::makeAuthenticatedUser($f['env']);
    $other = User::create(['environment_id' => $f['env']->id]);
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $other->id,
    ]);

    $r = meAuthorizedAppsReq('GET', '/me/authorized-apps', $me['jwt']);

    $r->assertOk()->assertJsonPath('total_count', 0);
});

it('DELETE stamps revoked_at on the grant and emits the webhook', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    $grant = AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $auth['user']->id,
    ]);

    $r = meAuthorizedAppsReq('DELETE', '/me/authorized-apps/'.$grant->id, $auth['jwt']);

    $r->assertOk()->assertJsonPath('id', $grant->id);
    expect($r->json('revoked_at'))->not->toBeNull();
    expect($grant->fresh()->isActive())->toBeFalse();
});

it('DELETE returns 404 for an unknown / cross-user grant', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $other = User::create(['environment_id' => $f['env']->id]);
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    $crossGrant = AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $other->id,
    ]);

    $r = meAuthorizedAppsReq('DELETE', '/me/authorized-apps/'.$crossGrant->id, $auth['jwt']);
    $r->assertStatus(404)->assertJsonPath('errors.0.code', 'authorization_grant_not_found');

    $r = meAuthorizedAppsReq('DELETE', '/me/authorized-apps/authgrant_nonexistent', $auth['jwt']);
    $r->assertStatus(404);
});

it('DELETE is idempotent on a grant the user already revoked', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    $grant = AuthorizationGrant::factory()->revoked()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $auth['user']->id,
    ]);

    $r = meAuthorizedAppsReq('DELETE', '/me/authorized-apps/'.$grant->id, $auth['jwt']);
    $r->assertOk()->assertJsonPath('id', $grant->id);
});
