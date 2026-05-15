<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Bapi;

use App\Models\ExternalAccount;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\OauthProviderFixtures;

beforeEach(function (): void {
    Bus::fake();
});

function ea_boot(string $slug): array
{
    $boot = BapiTestSupport::bootEnv($slug);
    $user = User::create(['environment_id' => $boot['env']->id]);
    $provider = OauthProviderFixtures::blankPreset($boot['env'], 'google');
    $ext = ExternalAccount::query()->withoutGlobalScopes()->create([
        'environment_id' => $boot['env']->id,
        'user_id' => $user->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'g-123',
        'email_address' => 'alice@example.com',
        'verified' => true,
        'scopes' => ['openid', 'email'],
        'public_metadata' => [],
        'encrypted_access_token' => 'at-1',
        'linked_at' => now(),
    ]);

    return ['boot' => $boot, 'user' => $user, 'provider' => $provider, 'ext' => $ext];
}

it('lists external accounts filtered by user_id', function (): void {
    $f = ea_boot('ea-list');

    $r = $this->withHeaders(BapiTestSupport::headers($f['boot']['token']))
        ->withoutOpenApiAssertions()
        ->get(BapiTestSupport::url('/external-accounts?user_id='.$f['user']->id));
    $r->assertOk();
    expect($r->json('data'))->toHaveCount(1);
    expect($r->json('data.0.provider_user_id'))->toBe('g-123');
});

it('returns a single external account by id', function (): void {
    $f = ea_boot('ea-show');

    $r = $this->withHeaders(BapiTestSupport::headers($f['boot']['token']))
        ->withoutOpenApiAssertions()
        ->get(BapiTestSupport::url('/external-accounts/'.$f['ext']->id));
    $r->assertOk();
    expect($r->json('id'))->toBe($f['ext']->id);
});

it('deletes an external account and best-effort revokes', function (): void {
    $f = ea_boot('ea-del');
    Http::fake([
        'idp.test/revoke' => Http::response(['ok' => true], 200),
    ]);
    $f['provider']->forceFill([
        'additional_authorization_params' => ['revocation_endpoint' => 'https://idp.test/revoke'],
    ])->save();

    $r = $this->withHeaders(BapiTestSupport::headers($f['boot']['token']))
        ->withoutOpenApiAssertions()
        ->deleteJson(BapiTestSupport::url('/external-accounts/'.$f['ext']->id));
    $r->assertStatus(204);
    expect(ExternalAccount::query()->withoutGlobalScopes()->where('id', $f['ext']->id)->exists())->toBeFalse();
    Http::assertSent(fn ($request) => $request->url() === 'https://idp.test/revoke');
});

it('deletes even when IdP revocation fails', function (): void {
    $f = ea_boot('ea-del-soft');
    Http::fake([
        'idp.test/revoke' => Http::response(['error' => 'unavailable'], 500),
    ]);
    $f['provider']->forceFill([
        'additional_authorization_params' => ['revocation_endpoint' => 'https://idp.test/revoke'],
    ])->save();

    $r = $this->withHeaders(BapiTestSupport::headers($f['boot']['token']))
        ->withoutOpenApiAssertions()
        ->deleteJson(BapiTestSupport::url('/external-accounts/'.$f['ext']->id));
    $r->assertStatus(204);
    expect(ExternalAccount::query()->withoutGlobalScopes()->where('id', $f['ext']->id)->exists())->toBeFalse();
});
