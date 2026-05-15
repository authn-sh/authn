<?php

declare(strict_types=1);

use App\Jobs\Webhooks\DispatchWebhookDelivery;
use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\PhoneNumber;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\Bapi\BapiTestSupport;
use Tests\Feature\Http\Me\MeTestSupport;
use Tests\Support\OauthProviderFixtures;

function au15Endpoint(Environment $env, array $types): WebhookEndpoint
{
    return WebhookEndpoint::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'url' => 'https://hook.test/ep',
        'enabled' => true,
        'enabled_event_types' => $types,
        'signing_secret' => 'whsec_'.bin2hex(random_bytes(8)),
    ]);
}

function au15MeReq(string $method, string $path, string $jwt, array $body = []): TestResponse
{
    return test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$jwt,
        ])
        ->json($method, "https://acme.authn.local/v1{$path}", $body);
}

it('emits oauthProvider.created/updated/deleted across the BAPI lifecycle', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $boot = BapiTestSupport::bootEnv('au15oauth');
    app()->instance(Environment::class, $boot['env']);
    au15Endpoint($boot['env'], ['oauthProvider.created', 'oauthProvider.updated', 'oauthProvider.deleted']);

    $createResp = test()->postJson(
        BapiTestSupport::url('/oauth-providers'),
        [
            'provider_kind' => 'custom_oauth2',
            'provider_key' => 'acme_au15',
            'name' => 'Acme',
            'enabled' => true,
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'authorization_endpoint' => 'https://acme.test/authorize',
            'token_endpoint' => 'https://acme.test/token',
            'userinfo_endpoint' => 'https://acme.test/userinfo',
            'userinfo_method' => 'GET',
            'userinfo_auth' => 'bearer',
        ],
        BapiTestSupport::headers($boot['token']),
    );
    $createResp->assertCreated();
    $providerId = $createResp->json('id');

    test()->patchJson(
        BapiTestSupport::url('/oauth-providers/'.$providerId),
        ['name' => 'Acme Renamed'],
        BapiTestSupport::headers($boot['token']),
    )->assertOk();

    test()->deleteJson(
        BapiTestSupport::url('/oauth-providers/'.$providerId),
        [],
        BapiTestSupport::headers($boot['token']),
    )->assertOk();

    $types = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $boot['env']->id)
        ->orderBy('created_at')
        ->pluck('type')
        ->all();
    expect($types)->toContain('oauthProvider.created')
        ->and($types)->toContain('oauthProvider.updated')
        ->and($types)->toContain('oauthProvider.deleted');

    Bus::assertDispatched(DispatchWebhookDelivery::class, 3);
});

it('emits phoneNumber.created on POST /v1/me/phone-numbers and phoneNumber.removed on DELETE', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = MeTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    au15Endpoint($f['env'], ['phoneNumber.created', 'phoneNumber.removed']);

    $createResp = au15MeReq('POST', '/me/phone-numbers', $auth['jwt'], ['phone_number' => '+15555550111']);
    $createResp->assertCreated();
    $phoneId = $createResp->json('response.id');

    au15MeReq('DELETE', '/me/phone-numbers/'.$phoneId, $auth['jwt'])->assertNoContent();

    $types = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->orderBy('created_at')
        ->pluck('type')
        ->all();
    expect($types)->toContain('phoneNumber.created')
        ->and($types)->toContain('phoneNumber.removed');
});

it('emits phoneNumber.verified once per row when markVerified() flips the cached flag', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = MeTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    au15Endpoint($f['env'], ['phoneNumber.verified']);

    $phone = PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'phone_number' => '+15555550112',
        'is_primary' => false,
    ]);

    $phone->markVerified();
    $phone->fresh()->markVerified(); // idempotent — must not double-emit.

    $count = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('type', 'phoneNumber.verified')
        ->count();
    expect($count)->toBe(1);
});

it('emits externalAccount.unlinked on DELETE /v1/me/external-accounts/{id}', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = MeTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    au15Endpoint($f['env'], ['externalAccount.unlinked']);

    $provider = OauthProviderFixtures::blankPreset($f['env'], 'google');
    $external = ExternalAccount::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $auth['user']->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'sub-au15',
        'email_address' => 'au15@example.test',
        'verified' => true,
        'scopes' => [],
        'public_metadata' => [],
        'encrypted_access_token' => 'tok',
        'linked_at' => now(),
        'last_signed_in_at' => now(),
    ]);

    au15MeReq('DELETE', '/me/external-accounts/'.$external->id, $auth['jwt'])->assertNoContent();

    $types = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->pluck('type')
        ->all();
    expect($types)->toContain('externalAccount.unlinked');
});
