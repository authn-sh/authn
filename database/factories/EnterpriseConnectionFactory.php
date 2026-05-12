<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EnterpriseConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnterpriseConnection>
 */
class EnterpriseConnectionFactory extends Factory
{
    /**
     * @var class-string<EnterpriseConnection>
     */
    protected $model = EnterpriseConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // environment_id supplied by the caller — Environment has no factory.
            'protocol' => EnterpriseConnection::PROTOCOL_SAML,
            'name' => $this->faker->company().' SSO',
            'enabled' => true,
            'organization_id' => null,
            'domains' => [],
            'default_role' => null,
            'attribute_mapping' => [],
            'saml_idp_entity_id' => 'https://idp.example.com/saml/metadata',
            'saml_sso_url' => 'https://idp.example.com/saml/sso',
            'saml_idp_certificate' => "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----",
            'saml_signing_algorithm' => 'RSA_SHA256',
            'saml_audience_uri' => null,
            'saml_signing_key' => null,
            'oidc_issuer' => null,
            'oidc_discovery_endpoint' => null,
            'oidc_client_id' => null,
            'oidc_client_secret' => null,
            'oidc_scopes' => [],
        ];
    }

    public function oidc(): self
    {
        return $this->state(fn (array $attributes): array => [
            'protocol' => EnterpriseConnection::PROTOCOL_OIDC,
            'saml_idp_entity_id' => null,
            'saml_sso_url' => null,
            'saml_idp_certificate' => null,
            'saml_signing_algorithm' => null,
            'oidc_issuer' => 'https://idp.example.com',
            'oidc_discovery_endpoint' => 'https://idp.example.com/.well-known/openid-configuration',
            'oidc_client_id' => 'client-'.$this->faker->bothify('????????'),
            'oidc_client_secret' => 'secret-'.$this->faker->bothify('????????????'),
            'oidc_scopes' => ['openid', 'email', 'profile'],
        ]);
    }

    public function disabled(): self
    {
        return $this->state(fn (array $attributes): array => ['enabled' => false]);
    }
}
