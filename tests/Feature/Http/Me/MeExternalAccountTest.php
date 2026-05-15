<?php

declare(strict_types=1);

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\OauthProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Me\MeTestSupport;
use Tests\Support\OauthProviderFixtures;

function meExtReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

function makeProviderForExt(string $envId): OauthProvider
{
    $env = Environment::query()->withoutGlobalScopes()->findOrFail($envId);

    return OauthProviderFixtures::blankPreset($env, 'google');
}

it('GET /v1/me/external-accounts lists rows for the current user', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $provider = makeProviderForExt($f['env']->id);
    ExternalAccount::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'g-1',
        'email_address' => 'alice@example.com',
        'verified' => true,
        'scopes' => ['openid', 'email'],
        'encrypted_access_token' => 'at-1',
        'linked_at' => now(),
    ]);

    $r = meExtReq('GET', '/me/external-accounts', $auth['jwt']);
    $r->assertOk()
        ->assertJsonPath('total_count', 1)
        ->assertJsonPath('data.0.object', 'external_account')
        ->assertJsonPath('data.0.provider_key', 'google')
        ->assertJsonPath('data.0.provider_user_id', 'g-1');
});

it('GET /v1/me/external-accounts/{id} 404s when the row is not on this user', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);

    meExtReq('GET', '/me/external-accounts/ext_nope', $auth['jwt'])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'external_account_not_found');
});

it('DELETE /v1/me/external-accounts/{id} unlinks the row + clears email pointer', function (): void {
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $provider = makeProviderForExt($f['env']->id);
    $ext = ExternalAccount::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'g-2',
        'email_address' => 'alice@example.com',
        'verified' => true,
        'encrypted_access_token' => 'at-2',
        'linked_at' => now(),
    ]);
    EmailAddress::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('email_address', strtolower($auth['user']->emailAddresses()->first()->email_address))
        ->update(['linked_to_external_account_id' => $ext->id]);

    $r = meExtReq('DELETE', '/me/external-accounts/'.$ext->id, $auth['jwt']);
    $r->assertStatus(204);

    expect(ExternalAccount::query()->withoutGlobalScopes()->where('id', $ext->id)->exists())->toBeFalse();
    $emails = EmailAddress::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->whereNotNull('linked_to_external_account_id')
        ->get();
    expect($emails)->toHaveCount(0);
});

it('DELETE /v1/me/external-accounts/{id} best-effort revokes against the IdP when revocation_endpoint is configured', function (): void {
    Http::fake([
        'https://idp.test/revoke' => Http::response('', 200),
    ]);

    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $provider = makeProviderForExt($f['env']->id);
    $provider->forceFill([
        'additional_authorization_params' => ['revocation_endpoint' => 'https://idp.test/revoke'],
    ])->save();

    $ext = ExternalAccount::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'g-3',
        'encrypted_access_token' => 'at-3',
        'encrypted_refresh_token' => 'rt-3',
        'linked_at' => now(),
    ]);

    meExtReq('DELETE', '/me/external-accounts/'.$ext->id, $auth['jwt'])
        ->assertStatus(204);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'idp.test/revoke')
        && $req['token'] === 'rt-3'
        && $req['client_id'] === $provider->client_id);
});

it('DELETE /v1/me/external-accounts/{id} succeeds even when the IdP revoke returns 4xx', function (): void {
    Http::fake([
        'https://idp.test/revoke' => Http::response('nope', 401),
    ]);

    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $provider = makeProviderForExt($f['env']->id);
    $provider->forceFill([
        'additional_authorization_params' => ['revocation_endpoint' => 'https://idp.test/revoke'],
    ])->save();
    $ext = ExternalAccount::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'g-4',
        'encrypted_access_token' => 'at-4',
        'linked_at' => now(),
    ]);

    meExtReq('DELETE', '/me/external-accounts/'.$ext->id, $auth['jwt'])
        ->assertStatus(204);

    expect(ExternalAccount::query()->withoutGlobalScopes()->where('id', $ext->id)->exists())->toBeFalse();
});
