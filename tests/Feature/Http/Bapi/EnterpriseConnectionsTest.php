<?php

declare(strict_types=1);

use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Http\Bapi\BapiTestSupport;

it('lists enterprise connections filtered by organization_id', function (): void {
    $f = BapiTestSupport::bootEnv();
    $token = $f['token'];

    $instance = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id, 'organization_id' => null]);
    $orgConn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id, 'organization_id' => null]);

    $r = $this->withHeaders(BapiTestSupport::headers($token))
        ->getJson(BapiTestSupport::url('/enterprise-connections'));

    $r->assertOk()->assertJsonPath('total_count', 2);
});

it('creates a SAML enterprise connection (instance-wide)', function (): void {
    $f = BapiTestSupport::bootEnv();
    $token = $f['token'];

    $r = $this->withHeaders(BapiTestSupport::headers($token))
        ->postJson(BapiTestSupport::url('/enterprise-connections'), [
            'protocol' => 'saml',
            'name' => 'Acme SSO',
            'domains' => ['acme.test'],
            'default_role' => 'org:member',
            'saml_idp_entity_id' => 'https://idp.example.com/saml/metadata',
            'saml_sso_url' => 'https://idp.example.com/saml/sso',
            'saml_idp_certificate' => "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----",
            'saml_signing_algorithm' => 'RSA_SHA256',
        ]);

    $r->assertCreated()
        ->assertJsonPath('object', 'enterprise_connection')
        ->assertJsonPath('protocol', 'saml')
        ->assertJsonPath('name', 'Acme SSO')
        ->assertJsonPath('domains.0', 'acme.test')
        ->assertJsonPath('organization_id', null);

    expect($r->json('saml_acs_url'))->toContain('/v1/saml/');
    expect($r->json('saml_sp_entity_id'))->toContain('/saml/');
});

it('creates an OIDC enterprise connection (instance-wide)', function (): void {
    $f = BapiTestSupport::bootEnv();
    $token = $f['token'];

    $r = $this->withHeaders(BapiTestSupport::headers($token))
        ->postJson(BapiTestSupport::url('/enterprise-connections'), [
            'protocol' => 'oidc',
            'name' => 'Acme OIDC',
            'oidc_issuer' => 'https://idp.example.com',
            'oidc_client_id' => 'client-xyz',
            'oidc_client_secret' => 'secret-abc',
            'oidc_scopes' => ['openid', 'email', 'profile'],
        ]);

    $r->assertCreated()
        ->assertJsonPath('protocol', 'oidc')
        ->assertJsonPath('oidc_client_id', 'client-xyz')
        ->assertJsonPath('oidc_scopes.0', 'openid');
    expect($r->json('oidc_redirect_uri'))->toContain('/v1/enterprise-sso-callback/');
});

it('rejects organization_id on BAPI create (per-org goes through FAPI)', function (): void {
    $f = BapiTestSupport::bootEnv();
    $token = $f['token'];

    $r = $this->withHeaders(BapiTestSupport::headers($token))
        ->postJson(BapiTestSupport::url('/enterprise-connections'), [
            'protocol' => 'saml',
            'name' => 'NotAllowed',
            'organization_id' => 'org_someid',
            'saml_idp_entity_id' => 'x',
            'saml_sso_url' => 'https://x',
            'saml_idp_certificate' => 'cert',
        ]);

    $r->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'organization_id_not_allowed');
});

it('patches a connection and rejects protocol changes', function (): void {
    $f = BapiTestSupport::bootEnv();
    $token = $f['token'];
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id, 'name' => 'Old']);

    $r = $this->withHeaders(BapiTestSupport::headers($token))
        ->patchJson(BapiTestSupport::url('/enterprise-connections/'.$conn->id), [
            'name' => 'New',
            'enabled' => false,
        ]);

    $r->assertOk()->assertJsonPath('name', 'New')->assertJsonPath('enabled', false);

    $r2 = $this->withHeaders(BapiTestSupport::headers($token))
        ->patchJson(BapiTestSupport::url('/enterprise-connections/'.$conn->id), [
            'protocol' => 'oidc',
        ]);
    $r2->assertStatus(422)->assertJsonPath('errors.0.code', 'protocol_immutable');
});

it('refuses to delete a connection with linked EnterpriseAccount rows', function (): void {
    $f = BapiTestSupport::bootEnv();
    $token = $f['token'];
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $user = User::create(['environment_id' => $f['env']->id]);
    EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $conn->id,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($token))
        ->deleteJson(BapiTestSupport::url('/enterprise-connections/'.$conn->id));

    $r->assertStatus(409)->assertJsonPath('errors.0.code', 'enterprise_connection_in_use');
});

it('soft-deletes a connection with no linked accounts', function (): void {
    $f = BapiTestSupport::bootEnv();
    $token = $f['token'];
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);

    $r = $this->withHeaders(BapiTestSupport::headers($token))
        ->deleteJson(BapiTestSupport::url('/enterprise-connections/'.$conn->id));

    $r->assertNoContent();
    expect(EnterpriseConnection::withTrashed()->withoutGlobalScopes()->where('id', $conn->id)->first()?->removed_at)->not->toBeNull();
});

it('reports OIDC discovery success in the test action', function (): void {
    $f = BapiTestSupport::bootEnv();
    $token = $f['token'];
    $conn = EnterpriseConnection::factory()->oidc()->create([
        'environment_id' => $f['env']->id,
        'oidc_issuer' => 'https://idp.example.com',
        'oidc_discovery_endpoint' => 'https://idp.example.com/.well-known/openid-configuration',
    ]);

    Http::fake([
        'idp.example.com/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/oauth/authorize',
            'token_endpoint' => 'https://idp.example.com/oauth/token',
            'userinfo_endpoint' => 'https://idp.example.com/oauth/userinfo',
            'jwks_uri' => 'https://idp.example.com/oauth/jwks',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($token))
        ->postJson(BapiTestSupport::url('/enterprise-connections/'.$conn->id.'/test'));

    $r->assertOk()
        ->assertJsonPath('discovery_status', 200)
        ->assertJsonPath('authorize_url', 'https://idp.example.com/oauth/authorize')
        ->assertJsonPath('errors', []);
});

it('reports SAML test errors when the connection lacks an IdP certificate', function (): void {
    $f = BapiTestSupport::bootEnv();
    $token = $f['token'];
    $conn = EnterpriseConnection::factory()->create([
        'environment_id' => $f['env']->id,
        'saml_idp_certificate' => null,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($token))
        ->postJson(BapiTestSupport::url('/enterprise-connections/'.$conn->id.'/test'));

    $r->assertOk()
        ->assertJsonPath('discovery_status', null)
        ->assertJsonPath('errors.0.code', 'saml_missing_field')
        ->assertJsonPath('errors.0.meta.param', 'saml_idp_certificate');
});
