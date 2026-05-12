<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Auth\EnterpriseSso\OidcConnectionService;
use App\Auth\Saml\SamlConnectionService;
use App\Models\EnterpriseConnection;

/**
 * BAPI/FAPI shape for `EnterpriseConnection`. Mirrors OA-1. Encrypted
 * fields (`saml_signing_key`, `oidc_client_secret`) are never returned;
 * computed read-only fields (`saml_acs_url`, `saml_sp_entity_id`,
 * `oidc_redirect_uri`) are derived from the env's FAPI host.
 */
final class EnterpriseConnectionResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(EnterpriseConnection $conn): array
    {
        $base = [
            'object' => 'enterprise_connection',
            'id' => $conn->id,
            'protocol' => $conn->protocol,
            'name' => $conn->name,
            'enabled' => (bool) $conn->enabled,
            'organization_id' => $conn->organization_id,
            'domains' => is_array($conn->domains) ? array_values($conn->domains) : [],
            'default_role' => $conn->default_role ?? '',
            'attribute_mapping' => is_array($conn->attribute_mapping) ? $conn->attribute_mapping : [],
            'created_at' => $conn->created_at?->getTimestampMs(),
            'updated_at' => $conn->updated_at?->getTimestampMs(),
        ];

        if ($conn->isSaml()) {
            $saml = app(SamlConnectionService::class);

            return array_merge($base, [
                'saml_idp_entity_id' => $conn->saml_idp_entity_id,
                'saml_sso_url' => $conn->saml_sso_url,
                'saml_idp_certificate' => $conn->saml_idp_certificate,
                'saml_signing_algorithm' => $conn->saml_signing_algorithm,
                'saml_audience_uri' => $conn->saml_audience_uri,
                'saml_acs_url' => $saml->acsUrl($conn),
                'saml_sp_entity_id' => $saml->spEntityId($conn),
            ]);
        }

        if ($conn->isOidc()) {
            $oidc = app(OidcConnectionService::class);

            return array_merge($base, [
                'oidc_issuer' => $conn->oidc_issuer,
                'oidc_discovery_endpoint' => $conn->oidc_discovery_endpoint,
                'oidc_client_id' => $conn->oidc_client_id,
                'oidc_scopes' => is_array($conn->oidc_scopes) ? array_values($conn->oidc_scopes) : [],
                'oidc_redirect_uri' => $oidc->redirectUri($conn),
            ]);
        }

        return $base;
    }
}
