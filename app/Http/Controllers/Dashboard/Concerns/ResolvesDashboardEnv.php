<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Concerns;

use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\OauthProvider;
use App\Models\OrganizationMembership;
use App\Models\Project;

trait ResolvesDashboardEnv
{
    private function env(string $projectSlug, string $envSlug): ?Environment
    {
        $project = Project::query()->withoutGlobalScopes()->where('slug', $projectSlug)->first();
        if ($project === null) {
            return null;
        }

        return $project->environments()->where('slug', $envSlug)->first();
    }

    private function workspace(): ?OrganizationMembership
    {
        $workspace = app()->bound('dashboard.workspace') ? app('dashboard.workspace') : null;

        return $workspace instanceof OrganizationMembership ? $workspace : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function oauthRowShape(OauthProvider $row): array
    {
        return [
            'id' => $row->id,
            'provider_kind' => $row->provider_kind,
            'provider_key' => $row->provider_key,
            'name' => $row->name,
            'enabled' => (bool) $row->enabled,
            'allow_sign_in' => (bool) $row->allow_sign_in,
            'allow_sign_up' => (bool) $row->allow_sign_up,
            'block_email_subaddresses' => (bool) $row->block_email_subaddresses,
            'client_id' => (string) $row->client_id,
            'client_secret_set' => $row->encrypted_client_secret !== '',
            'scopes' => is_array($row->scopes) ? array_values($row->scopes) : [],
            'attribute_mapping' => is_array($row->attribute_mapping) ? $row->attribute_mapping : [],
            'additional_authorization_params' => is_array($row->additional_authorization_params) ? $row->additional_authorization_params : [],
            'issuer' => $row->issuer,
            'authorization_endpoint' => $row->authorization_endpoint,
            'token_endpoint' => $row->token_endpoint,
            'userinfo_endpoint' => $row->userinfo_endpoint,
            'redirect_uri' => $row->computeRedirectUri(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enterpriseConnectionRowShape(EnterpriseConnection $row): array
    {
        $accountsCount = EnterpriseAccount::query()->withoutGlobalScopes()
            ->where('enterprise_connection_id', $row->id)
            ->count();

        return [
            'id' => $row->id,
            'protocol' => $row->protocol,
            'name' => $row->name,
            'enabled' => (bool) $row->enabled,
            'organization_id' => $row->organization_id,
            'domains' => is_array($row->domains) ? array_values($row->domains) : [],
            'default_role' => $row->default_role,
            'saml_idp_entity_id' => $row->saml_idp_entity_id,
            'saml_sso_url' => $row->saml_sso_url,
            'saml_signing_algorithm' => $row->saml_signing_algorithm,
            'oidc_issuer' => $row->oidc_issuer,
            'oidc_client_id' => $row->oidc_client_id,
            'oidc_scopes' => is_array($row->oidc_scopes) ? array_values($row->oidc_scopes) : [],
            'linked_accounts_count' => $accountsCount,
            'created_at' => $row->created_at?->getTimestampMs(),
        ];
    }
}
