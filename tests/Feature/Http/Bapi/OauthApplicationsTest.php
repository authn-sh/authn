<?php

declare(strict_types=1);

use App\Models\AuthorizationGrant;
use App\Models\OauthApplication;
use App\Models\User;
use Tests\Feature\Http\Bapi\BapiTestSupport;

it('lists OAuth applications paginated within the env', function (): void {
    $f = BapiTestSupport::bootEnv();
    OauthApplication::factory()->create(['environment_id' => $f['env']->id, 'name' => 'A1']);
    OauthApplication::factory()->create(['environment_id' => $f['env']->id, 'name' => 'A2']);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/oauth-applications'));

    $r->assertOk()->assertJsonPath('total_count', 2)
        ->assertJsonPath('data.0.object', 'oauth_application');
    expect($r->json('data.0.client_secret'))->toBeNull();
});

it('creates a confidential client and returns plaintext client_secret exactly once', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/oauth-applications'), [
            'name' => 'Acme Dashboard',
            'callback_urls' => ['https://app.acme.example/oauth/callback'],
            'scopes' => ['openid', 'profile', 'email'],
        ]);

    $r->assertCreated()
        ->assertJsonPath('object', 'oauth_application')
        ->assertJsonPath('name', 'Acme Dashboard')
        ->assertJsonPath('is_public', false);
    $plaintext = $r->json('client_secret');
    $id = $r->json('id');
    expect($plaintext)->toStartWith('osec_');
    expect($r->json('client_id'))->toBe('oac_pub_'.substr($id, strlen('oac_')));

    // Subsequent GETs never re-leak the plaintext.
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/oauth-applications/'.$id));
    $r->assertOk();
    expect($r->json('client_secret'))->toBeNull();

    // The minted plaintext verifies against the stored hash.
    $row = OauthApplication::query()->withoutGlobalScopes()->where('id', $id)->first();
    expect($row->verifyClientSecret($plaintext))->toBeTrue();
});

it('creates a public client with no client_secret stored', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/oauth-applications'), [
            'name' => 'Acme Mobile',
            'callback_urls' => ['com.acme.app://oauth/callback'],
            'scopes' => ['openid'],
            'is_public' => true,
        ]);

    $r->assertCreated()->assertJsonPath('is_public', true);
    expect($r->json())->not->toHaveKey('client_secret');
    $row = OauthApplication::query()->withoutGlobalScopes()->where('id', $r->json('id'))->first();
    expect($row->hashed_client_secret)->toBeNull();
});

it('validates callback_urls is required + non-empty on create', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/oauth-applications'), [
            'name' => 'No URLs',
            'callback_urls' => [],
        ]);
    $r->assertStatus(422);
});

it('refuses PATCH that flips is_public or replaces client_id with 422', function (): void {
    $f = BapiTestSupport::bootEnv();
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);

    $r1 = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/oauth-applications/'.$app->id), ['is_public' => true]);
    $r1->assertStatus(422)->assertJsonPath('errors.0.code', 'is_public_immutable');

    $r2 = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/oauth-applications/'.$app->id), ['client_id' => 'oac_pub_other']);
    $r2->assertStatus(422)->assertJsonPath('errors.0.code', 'client_id_immutable');
});

it('PATCH updates name + callback_urls + scopes', function (): void {
    $f = BapiTestSupport::bootEnv();
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id, 'name' => 'Old']);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/oauth-applications/'.$app->id), [
            'name' => 'New',
            'callback_urls' => ['https://new.example/cb'],
            'scopes' => ['openid', 'email'],
        ]);

    $r->assertOk()->assertJsonPath('name', 'New')
        ->assertJsonPath('callback_urls.0', 'https://new.example/cb')
        ->assertJsonPath('scopes.1', 'email');
});

it('rotate-secret mints a fresh plaintext and invalidates the previous one', function (): void {
    $f = BapiTestSupport::bootEnv();

    // Create via API so we have a verifiable plaintext to compare against.
    $created = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/oauth-applications'), [
            'name' => 'Rotatable',
            'callback_urls' => ['https://example.test/cb'],
        ]);
    $created->assertCreated();
    $id = $created->json('id');
    $oldPlaintext = $created->json('client_secret');

    $rotated = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/oauth-applications/'.$id.'/rotate-secret'));

    $rotated->assertOk();
    $newPlaintext = $rotated->json('client_secret');
    expect($newPlaintext)->toStartWith('osec_');
    expect($newPlaintext)->not->toBe($oldPlaintext);

    $row = OauthApplication::query()->withoutGlobalScopes()->where('id', $id)->first();
    expect($row->verifyClientSecret($newPlaintext))->toBeTrue();
    expect($row->verifyClientSecret($oldPlaintext))->toBeFalse();
});

it('rotate-secret refuses public clients with 409', function (): void {
    $f = BapiTestSupport::bootEnv();
    $app = OauthApplication::factory()->public()->create(['environment_id' => $f['env']->id]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/oauth-applications/'.$app->id.'/rotate-secret'));

    $r->assertStatus(409)->assertJsonPath('errors.0.code', 'oauth_application_public_client');
});

it('DELETE soft-removes the application and cascade-revokes its AuthorizationGrants', function (): void {
    $f = BapiTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    $app = OauthApplication::factory()->create(['environment_id' => $f['env']->id]);
    $grant = AuthorizationGrant::factory()->create([
        'environment_id' => $f['env']->id,
        'oauth_application_id' => $app->id,
        'user_id' => $user->id,
    ]);
    expect($grant->fresh()->isActive())->toBeTrue();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/oauth-applications/'.$app->id));
    $r->assertNoContent();

    expect($grant->fresh()->isActive())->toBeFalse();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/oauth-applications/'.$app->id));
    $r->assertStatus(404)->assertJsonPath('errors.0.code', 'oauth_application_not_found');
});

it('returns 404 for a row in another env on GET/PATCH/DELETE', function (): void {
    $f = BapiTestSupport::bootEnv('one');
    $other = BapiTestSupport::bootEnv('two');
    $app = OauthApplication::factory()->create(['environment_id' => $other['env']->id]);

    foreach (['GET', 'PATCH', 'DELETE'] as $method) {
        $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
            ->json($method, BapiTestSupport::url('/oauth-applications/'.$app->id), $method === 'PATCH' ? ['name' => 'x'] : []);
        $r->assertStatus(404);
    }
});
