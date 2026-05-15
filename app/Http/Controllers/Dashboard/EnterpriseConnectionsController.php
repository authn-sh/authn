<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EnterpriseConnectionsController
{
    use ResolvesDashboardEnv;

    public function storeEnterpriseConnection(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'protocol' => ['required', Rule::in(EnterpriseConnection::PROTOCOLS)],
            'name' => ['required', 'string', 'max:255'],
            'domains' => ['nullable', 'array'],
            'domains.*' => ['string'],
            'default_role' => ['nullable', 'string', 'max:255'],
            'saml_idp_entity_id' => ['nullable', 'string', 'max:512'],
            'saml_sso_url' => ['nullable', 'url', 'max:512'],
            'saml_idp_certificate' => ['nullable', 'string'],
            'saml_signing_algorithm' => ['nullable', 'string', 'max:128'],
            'oidc_issuer' => ['nullable', 'url', 'max:512'],
            'oidc_client_id' => ['nullable', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string'],
            'oidc_scopes' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $protocol = (string) $request->input('protocol');
        $payload = [
            'environment_id' => $env->id,
            'protocol' => $protocol,
            'name' => (string) $request->input('name'),
            'enabled' => $request->boolean('enabled', true),
            'domains' => is_array($request->input('domains')) ? $request->input('domains') : [],
            'default_role' => $request->input('default_role'),
        ];
        if ($protocol === EnterpriseConnection::PROTOCOL_SAML) {
            $payload += [
                'saml_idp_entity_id' => (string) $request->input('saml_idp_entity_id'),
                'saml_sso_url' => (string) $request->input('saml_sso_url'),
                'saml_idp_certificate' => (string) $request->input('saml_idp_certificate'),
                'saml_signing_algorithm' => $request->input('saml_signing_algorithm') ?: 'RSA_SHA256',
            ];
        } else {
            $payload += [
                'oidc_issuer' => (string) $request->input('oidc_issuer'),
                'oidc_client_id' => (string) $request->input('oidc_client_id'),
                'oidc_client_secret' => (string) $request->input('oidc_client_secret'),
                'oidc_scopes' => is_array($request->input('oidc_scopes')) ? $request->input('oidc_scopes') : ['openid', 'email', 'profile'],
            ];
        }

        EnterpriseConnection::query()->withoutGlobalScopes()->create($payload);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/enterprise-sso")
            ->with('enterprise_connection_saved', true);
    }

    public function destroyEnterpriseConnection(Request $request, string $project_slug, string $env_slug, string $enterprise_connection_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $conn = EnterpriseConnection::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $enterprise_connection_id)
            ->first();
        if ($conn !== null) {
            $linked = EnterpriseAccount::query()->withoutGlobalScopes()
                ->where('enterprise_connection_id', $conn->id)
                ->exists();
            if ($linked) {
                return redirect()->back()->withErrors([
                    'enterprise_connection' => 'Cannot delete — accounts are still linked. Unlink users first.',
                ]);
            }
            $conn->delete();
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/enterprise-sso")
            ->with('enterprise_connection_deleted', true);
    }
}
