<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Bapi;

use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\OauthProviderFixtures;
use Tests\TestCase;

final class OauthProvidersTest extends TestCase
{
    public function test_index_returns_persisted_preset_rows(): void
    {
        $boot = BapiTestSupport::bootEnv('oauthlist');
        app()->instance(Environment::class, $boot['env']);
        foreach (['apple', 'discord', 'facebook', 'github', 'gitlab',
            'google', 'linkedin', 'microsoft', 'slack', 'x'] as $key) {
            OauthProviderFixtures::blankPreset($boot['env'], $key);
        }

        $resp = $this->getJson(BapiTestSupport::url('/oauth-providers'), BapiTestSupport::headers($boot['token']));

        $resp->assertOk();
        $keys = collect($resp->json('data'))->pluck('provider_key')->sort()->values()->all();
        $this->assertSame([
            'apple', 'discord', 'facebook', 'github', 'gitlab',
            'google', 'linkedin', 'microsoft', 'slack', 'x',
        ], $keys);
    }

    public function test_show_returns_one_provider_with_redirect_uri(): void
    {
        $boot = BapiTestSupport::bootEnv('oauthshow');
        app()->instance(Environment::class, $boot['env']);
        $row = OauthProviderFixtures::blankPreset($boot['env'], 'google');

        $resp = $this->getJson(
            BapiTestSupport::url('/oauth-providers/'.$row->id),
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $resp->assertJson([
            'object' => 'oauth_provider',
            'provider_kind' => 'preset',
            'provider_key' => 'google',
        ]);
        $this->assertStringContainsString('/v1/oauth-callback/google', $resp->json('redirect_uri'));
    }

    public function test_store_creates_a_custom_oauth2_provider(): void
    {
        $boot = BapiTestSupport::bootEnv('oauthcreate');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->postJson(
            BapiTestSupport::url('/oauth-providers'),
            [
                'provider_kind' => 'custom_oauth2',
                'provider_key' => 'acmecorp',
                'name' => 'Acme Corp',
                'enabled' => true,
                'client_id' => 'cid-1',
                'client_secret' => 'sec-1',
                'authorization_endpoint' => 'https://acme.test/authorize',
                'token_endpoint' => 'https://acme.test/token',
                'userinfo_endpoint' => 'https://acme.test/userinfo',
                'userinfo_method' => 'GET',
                'userinfo_auth' => 'bearer',
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertCreated();
        $resp->assertJson([
            'object' => 'oauth_provider',
            'provider_kind' => 'custom_oauth2',
            'provider_key' => 'acmecorp',
            'name' => 'Acme Corp',
            'enabled' => true,
        ]);
        $this->assertArrayNotHasKey('client_secret', $resp->json());
    }

    public function test_store_runs_oid_c_discovery_on_custom_oidc(): void
    {
        Http::fake([
            'https://idp.acme.test/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://idp.acme.test/authorize',
                'token_endpoint' => 'https://idp.acme.test/token',
                'userinfo_endpoint' => 'https://idp.acme.test/userinfo',
                'jwks_uri' => 'https://idp.acme.test/jwks',
                'id_token_signing_alg_values_supported' => ['RS256'],
            ], 200),
        ]);

        $boot = BapiTestSupport::bootEnv('oauthoidc');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->postJson(
            BapiTestSupport::url('/oauth-providers'),
            [
                'provider_kind' => 'custom_oidc',
                'provider_key' => 'acmeoidc',
                'name' => 'Acme OIDC',
                'client_id' => 'cid',
                'client_secret' => 'sec',
                'issuer' => 'https://idp.acme.test',
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertCreated();
        $resp->assertJson([
            'authorization_endpoint' => 'https://idp.acme.test/authorize',
            'jwks_uri' => 'https://idp.acme.test/jwks',
        ]);
    }

    public function test_store_422s_on_custom_oidc_without_issuer(): void
    {
        $boot = BapiTestSupport::bootEnv('oauth422');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->postJson(
            BapiTestSupport::url('/oauth-providers'),
            [
                'provider_kind' => 'custom_oidc',
                'provider_key' => 'noissuer',
                'name' => 'X',
                'client_id' => 'c',
                'client_secret' => 's',
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertStatus(422);
    }

    public function test_store_409_on_duplicate_provider_key(): void
    {
        $boot = BapiTestSupport::bootEnv('oauthdup');
        app()->instance(Environment::class, $boot['env']);
        OauthProviderFixtures::blankPreset($boot['env'], 'google');

        $resp = $this->postJson(
            BapiTestSupport::url('/oauth-providers'),
            [
                'provider_kind' => 'preset',
                'provider_key' => 'google',
                'name' => 'Dup Google',
                'client_id' => 'a',
                'client_secret' => 'b',
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertStatus(409);
        $this->assertSame('oauth_provider_exists', $resp->json('errors.0.code'));
    }

    public function test_update_writes_through_with_partial_patch(): void
    {
        $boot = BapiTestSupport::bootEnv('oauthpatch');
        app()->instance(Environment::class, $boot['env']);
        $row = OauthProviderFixtures::blankPreset($boot['env'], 'google');

        $resp = $this->patchJson(
            BapiTestSupport::url('/oauth-providers/'.$row->id),
            ['enabled' => true, 'client_id' => 'real-client', 'client_secret' => 'rotated'],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $resp->assertJson(['enabled' => true, 'client_id' => 'real-client']);

        $row->refresh();
        $this->assertSame('rotated', $row->encrypted_client_secret);
    }

    public function test_update_rejects_provider_kind_changes(): void
    {
        $boot = BapiTestSupport::bootEnv('oauthimm');
        app()->instance(Environment::class, $boot['env']);
        $row = OauthProviderFixtures::blankPreset($boot['env'], 'google');

        $resp = $this->patchJson(
            BapiTestSupport::url('/oauth-providers/'.$row->id),
            ['provider_kind' => 'custom_oidc'],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertStatus(422);
    }

    public function test_destroy_409s_when_external_accounts_link_to_the_provider(): void
    {
        $boot = BapiTestSupport::bootEnv('oauthdel');
        app()->instance(Environment::class, $boot['env']);
        $row = OauthProviderFixtures::blankPreset($boot['env'], 'google');
        $user = User::query()->withoutGlobalScopes()->create(['environment_id' => $boot['env']->id]);
        ExternalAccount::query()->withoutGlobalScopes()->create([
            'environment_id' => $boot['env']->id,
            'user_id' => $user->id,
            'oauth_provider_id' => $row->id,
            'provider_user_id' => 'pid',
            'encrypted_access_token' => 't',
            'linked_at' => now(),
        ]);

        $resp = $this->deleteJson(
            BapiTestSupport::url('/oauth-providers/'.$row->id),
            [],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertStatus(409);
        $this->assertSame('oauth_provider_in_use', $resp->json('errors.0.code'));
    }

    public function test_destroy_soft_deletes_when_no_links(): void
    {
        $boot = BapiTestSupport::bootEnv('oauthdel2');
        app()->instance(Environment::class, $boot['env']);
        $row = OauthProviderFixtures::blankPreset($boot['env'], 'google');

        $resp = $this->deleteJson(
            BapiTestSupport::url('/oauth-providers/'.$row->id),
            [],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $resp->assertJson(['object' => 'deleted_object', 'deleted' => true]);
    }

    public function test_test_action_returns_authorize_url_and_userinfo_status(): void
    {
        Http::fake([
            'openidconnect.googleapis.com/*' => Http::response('', 401),
        ]);

        $boot = BapiTestSupport::bootEnv('oauthtest');
        app()->instance(Environment::class, $boot['env']);
        $row = OauthProviderFixtures::blankPreset($boot['env'], 'google');
        $row->forceFill(['client_id' => 'real-client'])->save();

        $resp = $this->postJson(
            BapiTestSupport::url('/oauth-providers/'.$row->id.'/test'),
            [],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $this->assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $resp->json('authorize_url'));
        $this->assertSame(401, $resp->json('userinfo_status'));
        $this->assertSame([], $resp->json('errors'));
    }
}
