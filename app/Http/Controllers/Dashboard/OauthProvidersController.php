<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Auth\Oauth\Exceptions\OauthDiscoveryFailedException;
use App\Auth\Oauth\OauthProviderResolver;
use App\Auth\Oauth\PresetRegistry;
use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Http\Resources\OauthProviderResource;
use App\Models\OauthProvider;
use App\Support\Url;
use App\Webhooks\Emitter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

final class OauthProvidersController
{
    use ResolvesDashboardEnv;

    public function __construct(
        private readonly OauthProviderResolver $oauthResolver,
        private readonly PresetRegistry $oauthPresets,
    ) {}

    public function provider(string $project_slug, string $env_slug, string $provider_key): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $row = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('provider_key', $provider_key)
            ->first();
        $preset = $this->oauthPresets->get($provider_key);

        if ($row === null && $preset === null) {
            abort(404);
        }

        return Inertia::render('Dashboard/Provider', [
            'provider' => $row !== null ? $this->oauthRowShape($row) : null,
            'preset' => $preset === null ? null : [
                'key' => $preset->key(),
                'name' => $preset->name(),
                'default_scopes' => $preset->defaultScopes(),
                'available_scopes' => $preset->availableScopes(),
                'authorization_endpoint' => $preset->authorizationEndpoint(),
                'token_endpoint' => $preset->tokenEndpoint(),
                'userinfo_endpoint' => $preset->userinfoEndpoint(),
                'issuer' => $preset->issuer(),
            ],
            'provider_key' => $provider_key,
            'docs_url' => "https://authn.sh/docs/providers/{$provider_key}",
        ]);
    }

    public function newCustomOauthProvider(Request $request, string $project_slug, string $env_slug): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $kind = (string) $request->route()->defaults['kind'];

        return Inertia::render('Dashboard/Provider', [
            'provider' => null,
            'preset' => null,
            'provider_key' => null,
            'new_kind' => $kind,
            'docs_url' => $kind === 'custom_oidc'
                ? 'https://authn.sh/docs/providers/custom-oidc'
                : 'https://authn.sh/docs/providers/custom-oauth2',
        ]);
    }

    public function storeOauthProvider(Request $request, string $project_slug, string $env_slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'provider_kind' => ['required', Rule::in(OauthProvider::KINDS)],
            'provider_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1024'],
            'issuer' => ['nullable', 'url', 'max:512'],
            'authorization_endpoint' => ['nullable', 'url', 'max:512'],
            'token_endpoint' => ['nullable', 'url', 'max:512'],
            'userinfo_endpoint' => ['nullable', 'url', 'max:512'],
            'userinfo_method' => ['nullable', 'in:GET,POST'],
            'userinfo_auth' => ['nullable', 'in:bearer,basic,query'],
            'scopes' => ['nullable', 'array'],
            'attribute_mapping' => ['nullable', 'array'],
            'additional_authorization_params' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $kind = (string) $request->input('provider_kind');
        $providerKey = (string) $request->input('provider_key');

        $duplicate = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('provider_key', $providerKey)
            ->exists();
        if ($duplicate) {
            return redirect()->back()->withErrors(['provider_key' => 'A provider with that key already exists.']);
        }

        $payload = [
            'environment_id' => $env->id,
            'provider_kind' => $kind,
            'provider_key' => $providerKey,
            'name' => (string) $request->input('name'),
            'enabled' => $request->boolean('enabled', false),
            'client_id' => (string) $request->input('client_id'),
            'encrypted_client_secret' => (string) $request->input('client_secret', ''),
            'scopes' => is_array($request->input('scopes')) ? $request->input('scopes') : [],
            'attribute_mapping' => is_array($request->input('attribute_mapping')) ? $request->input('attribute_mapping') : [],
            'additional_authorization_params' => is_array($request->input('additional_authorization_params')) ? $request->input('additional_authorization_params') : [],
        ];

        if ($kind === OauthProvider::KIND_PRESET) {
            $preset = $this->oauthPresets->get($providerKey);
            if ($preset !== null) {
                $payload['authorization_endpoint'] = $preset->authorizationEndpoint();
                $payload['token_endpoint'] = $preset->tokenEndpoint();
                $payload['userinfo_endpoint'] = $preset->userinfoEndpoint();
                $payload['jwks_uri'] = $preset->jwksUri();
                $payload['id_token_signing_algs'] = $preset->idTokenSigningAlgs();
                $payload['userinfo_method'] = $preset->userinfoMethod();
                $payload['userinfo_auth'] = $preset->userinfoAuth();
                if ($payload['scopes'] === []) {
                    $payload['scopes'] = $preset->defaultScopes();
                }
                if ($payload['attribute_mapping'] === []) {
                    $payload['attribute_mapping'] = $preset->defaultAttributeMapping();
                }
                if ($payload['additional_authorization_params'] === []) {
                    $payload['additional_authorization_params'] = $preset->additionalAuthorizationParams();
                }
            }
        }

        if ($kind === OauthProvider::KIND_CUSTOM_OIDC) {
            $issuer = (string) $request->input('issuer');
            if ($issuer === '') {
                return redirect()->back()->withErrors(['issuer' => 'issuer is required for custom OIDC.']);
            }
            try {
                $endpoints = $this->oauthResolver->discoverEndpoints($issuer);
                $payload = array_merge($payload, [
                    'issuer' => $issuer,
                    'authorization_endpoint' => $endpoints['authorization_endpoint'],
                    'token_endpoint' => $endpoints['token_endpoint'],
                    'userinfo_endpoint' => $endpoints['userinfo_endpoint'],
                    'jwks_uri' => $endpoints['jwks_uri'],
                    'id_token_signing_algs' => $endpoints['id_token_signing_algs'],
                    'discovery_cached_at' => now(),
                ]);
            } catch (OauthDiscoveryFailedException $e) {
                return redirect()->back()->withErrors(['issuer' => $e->getMessage()]);
            }
        }

        if ($kind === OauthProvider::KIND_CUSTOM_OAUTH2) {
            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'userinfo_method', 'userinfo_auth'] as $field) {
                if (! $request->filled($field)) {
                    return redirect()->back()->withErrors([$field => "{$field} is required for custom OAuth2."]);
                }
                $payload[$field] = $request->input($field);
            }
        }

        $created = OauthProvider::query()->withoutGlobalScopes()->create($payload);

        app(Emitter::class)->emit('oauthProvider.created', OauthProviderResource::from($created), $env);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/social-providers")
            ->with('oauth_provider_saved', true);
    }

    public function updateOauthProvider(Request $request, string $project_slug, string $env_slug, string $oauth_provider_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_provider_id)
            ->first();
        if ($row === null) {
            return redirect()->back()->withErrors(['oauth_provider_id' => 'Provider not found.']);
        }

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'allow_sign_in' => ['sometimes', 'boolean'],
            'allow_sign_up' => ['sometimes', 'boolean'],
            'block_email_subaddresses' => ['sometimes', 'boolean'],
            'client_id' => ['sometimes', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1024'],
            'scopes' => ['sometimes', 'array'],
            'attribute_mapping' => ['sometimes', 'array'],
            'additional_authorization_params' => ['sometimes', 'array'],
        ]);

        $patch = [];
        foreach (['name', 'enabled', 'allow_sign_in', 'allow_sign_up', 'block_email_subaddresses', 'client_id', 'scopes', 'attribute_mapping', 'additional_authorization_params'] as $field) {
            if ($request->has($field)) {
                $patch[$field] = $request->input($field);
            }
        }
        // Only rotate the secret on a non-empty value.
        $secret = $request->input('client_secret');
        if (is_string($secret) && $secret !== '') {
            $patch['encrypted_client_secret'] = $secret;
        }

        $row->forceFill($patch)->save();

        app(Emitter::class)->emit('oauthProvider.updated', OauthProviderResource::from($row->refresh()), $env);

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/social-providers")
            ->with('oauth_provider_saved', true);
    }

    public function testOauthProvider(string $project_slug, string $env_slug, string $oauth_provider_id): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $row = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauth_provider_id)
            ->first();
        if ($row === null) {
            return redirect()->back()->withErrors(['oauth_provider_id' => 'Provider not found.']);
        }

        $errors = [];
        $userinfoStatus = null;
        try {
            $resolved = $this->oauthResolver->resolve($row);
            if ($resolved->userinfoEndpoint !== '' && $resolved->userinfoEndpoint !== $resolved->tokenEndpoint) {
                try {
                    $userinfoStatus = Http::timeout(5)->get($resolved->userinfoEndpoint)->status();
                } catch (ConnectionException|RequestException|Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } catch (OauthDiscoveryFailedException $e) {
            $errors[] = $e->getMessage();
        }

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/social-providers")
            ->with('oauth_provider_test', [
                'provider_id' => $row->id,
                'userinfo_status' => $userinfoStatus,
                'errors' => $errors,
            ]);
    }
}
