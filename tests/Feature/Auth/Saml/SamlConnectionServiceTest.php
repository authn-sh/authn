<?php

declare(strict_types=1);

use App\Auth\Saml\SamlConnectionService;
use App\Auth\Saml\SamlException;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\Project;

function makeSamlEnv(string $slug = 'acme'): Environment
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

function makeSamlConnection(Environment $env): EnterpriseConnection
{
    return EnterpriseConnection::factory()->create([
        'environment_id' => $env->id,
        'saml_idp_entity_id' => 'https://idp.example.com/saml/metadata',
        'saml_sso_url' => 'https://idp.example.com/saml/sso',
        'saml_idp_certificate' => "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----",
    ]);
}

it('computes the ACS URL + SP entity id off the env FAPI host and connection id', function (): void {
    $env = makeSamlEnv('acme');
    $conn = makeSamlConnection($env);

    $service = new SamlConnectionService;

    expect($service->acsUrl($conn))->toBe('https://acme.authn.local/v1/saml/'.$conn->id.'/acs');
    expect($service->spEntityId($conn))->toBe('https://acme.authn.local/saml/'.$conn->id);
});

it('emits SP metadata XML with our ACS endpoint + SP entity id', function (): void {
    $env = makeSamlEnv('acme');
    $conn = makeSamlConnection($env);

    $xml = (new SamlConnectionService)->buildSpMetadata($conn);

    expect($xml)->toContain('<?xml');
    expect($xml)->toContain('EntityDescriptor');
    expect($xml)->toContain('entityID="https://acme.authn.local/saml/'.$conn->id.'"');
    expect($xml)->toContain('AssertionConsumerService');
    expect($xml)->toContain('Location="https://acme.authn.local/v1/saml/'.$conn->id.'/acs"');
    expect($xml)->toContain('WantAssertionsSigned="true"');
});

it('builds a base64-encoded AuthnRequest pointing at the IdP SSO URL', function (): void {
    $env = makeSamlEnv('acme');
    $conn = makeSamlConnection($env);

    $b64 = (new SamlConnectionService)->buildAuthnRequest($conn, relayState: 'sid:sia_abc123');

    expect($b64)->not->toBeEmpty();
    $xml = base64_decode($b64, true);
    expect($xml)->toBeString();
    expect($xml)->toContain('AuthnRequest');
    expect($xml)->toContain('Destination="https://idp.example.com/saml/sso"');
    expect($xml)->toContain('AssertionConsumerServiceURL="https://acme.authn.local/v1/saml/'.$conn->id.'/acs"');
    expect($xml)->toContain('<saml:Issuer');
    expect($xml)->toContain('https://acme.authn.local/saml/'.$conn->id);
});

it('rejects an obviously malformed SAMLResponse', function (): void {
    $env = makeSamlEnv('acme');
    $conn = makeSamlConnection($env);

    $service = new SamlConnectionService;

    expect(fn () => $service->parseSamlResponse('!!!not-base64!!!', $conn))
        ->toThrow(SamlException::class);
});

it('rejects a SAMLResponse that has no Assertion', function (): void {
    $env = makeSamlEnv('acme');
    $conn = makeSamlConnection($env);

    // Minimal Response with no Assertion children.
    $responseXml = <<<'XML'
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
                xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
                ID="r1" Version="2.0" IssueInstant="2026-05-11T12:00:00Z">
  <saml:Issuer>https://idp.example.com/saml/metadata</saml:Issuer>
  <samlp:Status>
    <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
  </samlp:Status>
</samlp:Response>
XML;

    expect(fn () => (new SamlConnectionService)->parseSamlResponse(base64_encode($responseXml), $conn))
        ->toThrow(SamlException::class);
});
