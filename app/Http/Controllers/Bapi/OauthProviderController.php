<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Auth\Oauth\Exceptions\OauthDiscoveryFailedException;
use App\Auth\Oauth\OauthProviderResolver;
use App\Auth\Oauth\PresetRegistry;
use App\Http\Requests\Bapi\OauthProviders\OauthProviderStoreRequest;
use App\Http\Requests\Bapi\OauthProviders\OauthProviderUpdateRequest;
use App\Http\Resources\OauthProviderResource;
use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\OauthProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BAPI OAuth providers surface (PLAN §4.6, OA-4).
 *
 *   GET    /v1/oauth-providers
 *   POST   /v1/oauth-providers
 *   GET    /v1/oauth-providers/{oauth_provider_id}
 *   PATCH  /v1/oauth-providers/{oauth_provider_id}
 *   DELETE /v1/oauth-providers/{oauth_provider_id}   — 409 when ExternalAccounts present
 *   POST   /v1/oauth-providers/{oauth_provider_id}/test
 */
final class OauthProviderController
{
    public function __construct(
        private readonly OauthProviderResolver $resolver,
        private readonly PresetRegistry $presets,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('provider_key');

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OauthProvider $r) => OauthProviderResource::from($r))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(OauthProviderStoreRequest $request): JsonResponse
    {
        $env = app(Environment::class);

        $providerKey = (string) $request->input('provider_key');
        $duplicate = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('provider_key', $providerKey)
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'oauth_provider_exists', 'A provider with that provider_key already exists in this environment.');
        }

        $payload = $this->buildCreatePayload($env, $request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $provider = OauthProvider::query()->withoutGlobalScopes()->create($payload);

        Log::info('audit:bapi.oauth_provider.created', [
            'environment_id' => $env->id,
            'oauth_provider_id' => $provider->id,
            'provider_kind' => $provider->provider_kind,
            'provider_key' => $provider->provider_key,
        ]);

        return response()->json(OauthProviderResource::from($provider), 201);
    }

    public function show(string $oauthProviderId): JsonResponse
    {
        $row = $this->find($oauthProviderId);
        if ($row === null) {
            return $this->error(404, 'oauth_provider_not_found', 'No OAuth provider matches that id in this environment.');
        }

        return response()->json(OauthProviderResource::from($row))
            ->header('Cache-Control', 'no-store');
    }

    public function update(OauthProviderUpdateRequest $request, string $oauthProviderId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($oauthProviderId);
        if ($row === null) {
            return $this->error(404, 'oauth_provider_not_found', 'No OAuth provider matches that id in this environment.');
        }

        $payload = $request->validated();

        // client_secret semantics: omitted → unchanged; null → clear; string → re-encrypt.
        if (array_key_exists('client_secret', $payload)) {
            $secret = $payload['client_secret'];
            $payload['encrypted_client_secret'] = $secret === null ? '' : (string) $secret;
            unset($payload['client_secret']);
        }

        // Refresh discovery when issuer changes for custom_oidc.
        if ($row->provider_kind === OauthProvider::KIND_CUSTOM_OIDC
            && array_key_exists('issuer', $payload)
            && (string) $payload['issuer'] !== (string) $row->issuer
        ) {
            try {
                $endpoints = $this->resolver->discoverEndpoints((string) $payload['issuer']);
                $payload = array_merge($payload, [
                    'authorization_endpoint' => $endpoints['authorization_endpoint'],
                    'token_endpoint' => $endpoints['token_endpoint'],
                    'userinfo_endpoint' => $endpoints['userinfo_endpoint'],
                    'jwks_uri' => $endpoints['jwks_uri'],
                    'id_token_signing_algs' => $endpoints['id_token_signing_algs'],
                    'discovery_cached_at' => now(),
                ]);
            } catch (OauthDiscoveryFailedException $e) {
                return $this->error(422, 'oauth_discovery_failed', $e->getMessage());
            }
        }

        $row->forceFill($payload)->save();

        Log::info('audit:bapi.oauth_provider.updated', [
            'environment_id' => $env->id,
            'oauth_provider_id' => $row->id,
        ]);

        return response()->json(OauthProviderResource::from($row->refresh()));
    }

    public function destroy(string $oauthProviderId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($oauthProviderId);
        if ($row === null) {
            return $this->error(404, 'oauth_provider_not_found', 'No OAuth provider matches that id in this environment.');
        }

        $linked = ExternalAccount::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('oauth_provider_id', $row->id)
            ->exists();
        if ($linked) {
            return $this->error(409, 'oauth_provider_in_use', 'Cannot delete: ExternalAccount rows still link to this provider.');
        }

        $row->delete();

        Log::info('audit:bapi.oauth_provider.deleted', [
            'environment_id' => $env->id,
            'oauth_provider_id' => $row->id,
        ]);

        return response()->json([
            'object' => 'deleted_object',
            'id' => $oauthProviderId,
            'deleted' => true,
        ]);
    }

    public function test(string $oauthProviderId): JsonResponse
    {
        $row = $this->find($oauthProviderId);
        if ($row === null) {
            return $this->error(404, 'oauth_provider_not_found', 'No OAuth provider matches that id in this environment.');
        }

        $errors = [];
        $authorizeUrl = '';
        $userinfoStatus = null;

        try {
            $resolved = $this->resolver->resolve($row);
        } catch (OauthDiscoveryFailedException $e) {
            $errors[] = ['code' => 'oauth_discovery_failed', 'message' => $e->getMessage(), 'long_message' => $e->getMessage(), 'meta' => []];
            $resolved = null;
        }

        if ($resolved !== null) {
            $authorizeUrl = $this->buildAuthorizeUrl($resolved->authorizationEndpoint, $row, $resolved->scopes, $resolved->additionalAuthorizationParams);
            if ($resolved->userinfoEndpoint !== '' && $resolved->userinfoEndpoint !== $resolved->tokenEndpoint) {
                try {
                    $response = Http::timeout(5)->get($resolved->userinfoEndpoint);
                    $userinfoStatus = $response->status();
                } catch (ConnectionException|RequestException|Throwable $e) {
                    $errors[] = ['code' => 'userinfo_unreachable', 'message' => $e->getMessage(), 'long_message' => $e->getMessage(), 'meta' => []];
                }
            }
        }

        return response()->json([
            'authorize_url' => $authorizeUrl,
            'userinfo_status' => $userinfoStatus,
            'errors' => $errors,
        ]);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function buildCreatePayload(Environment $env, OauthProviderStoreRequest $request): array|JsonResponse
    {
        $kind = (string) $request->input('provider_kind');
        $base = [
            'environment_id' => $env->id,
            'provider_kind' => $kind,
            'provider_key' => (string) $request->input('provider_key'),
            'name' => (string) $request->input('name'),
            'enabled' => $request->boolean('enabled', false),
            'allow_sign_in' => $request->boolean('allow_sign_in', true),
            'allow_sign_up' => $request->boolean('allow_sign_up', true),
            'block_email_subaddresses' => $request->boolean('block_email_subaddresses', false),
            'client_id' => (string) $request->input('client_id'),
            'encrypted_client_secret' => (string) $request->input('client_secret', ''),
            'scopes' => $this->arrayInput($request, 'scopes'),
            'additional_authorization_params' => $this->arrayInput($request, 'additional_authorization_params'),
            'attribute_mapping' => $this->arrayInput($request, 'attribute_mapping'),
        ];

        if ($kind === OauthProvider::KIND_PRESET) {
            $preset = $this->presets->get($base['provider_key']);
            if ($preset !== null) {
                $base['authorization_endpoint'] = $preset->authorizationEndpoint();
                $base['token_endpoint'] = $preset->tokenEndpoint();
                $base['userinfo_endpoint'] = $preset->userinfoEndpoint();
                $base['jwks_uri'] = $preset->jwksUri();
                $base['id_token_signing_algs'] = $preset->idTokenSigningAlgs();
                $base['userinfo_method'] = $preset->userinfoMethod();
                $base['userinfo_auth'] = $preset->userinfoAuth();
                if ($base['scopes'] === []) {
                    $base['scopes'] = $preset->defaultScopes();
                }
                if ($base['attribute_mapping'] === []) {
                    $base['attribute_mapping'] = $preset->defaultAttributeMapping();
                }
                if ($base['additional_authorization_params'] === []) {
                    $base['additional_authorization_params'] = $preset->additionalAuthorizationParams();
                }
            }
        }

        if ($kind === OauthProvider::KIND_CUSTOM_OIDC) {
            $base['issuer'] = (string) $request->input('issuer');
            try {
                $endpoints = $this->resolver->discoverEndpoints($base['issuer']);
                $base = array_merge($base, [
                    'authorization_endpoint' => $endpoints['authorization_endpoint'],
                    'token_endpoint' => $endpoints['token_endpoint'],
                    'userinfo_endpoint' => $endpoints['userinfo_endpoint'],
                    'jwks_uri' => $endpoints['jwks_uri'],
                    'id_token_signing_algs' => $endpoints['id_token_signing_algs'],
                    'discovery_cached_at' => now(),
                ]);
            } catch (OauthDiscoveryFailedException $e) {
                return $this->error(422, 'oauth_discovery_failed', $e->getMessage());
            }
        }

        if ($kind === OauthProvider::KIND_CUSTOM_OAUTH2) {
            $base['authorization_endpoint'] = (string) $request->input('authorization_endpoint');
            $base['token_endpoint'] = (string) $request->input('token_endpoint');
            $base['userinfo_endpoint'] = (string) $request->input('userinfo_endpoint');
            $base['userinfo_method'] = (string) $request->input('userinfo_method');
            $base['userinfo_auth'] = (string) $request->input('userinfo_auth');
        }

        return $base;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayInput(Request $request, string $key): array
    {
        $value = $request->input($key);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<int, string>  $scopes
     * @param  array<string, mixed>  $extraParams
     */
    private function buildAuthorizeUrl(string $authEndpoint, OauthProvider $row, array $scopes, array $extraParams): string
    {
        if ($authEndpoint === '') {
            return '';
        }

        $params = array_merge([
            'response_type' => 'code',
            'client_id' => $row->client_id,
            'redirect_uri' => $row->computeRedirectUri(),
            'scope' => implode(' ', $scopes),
            'state' => 'test_'.bin2hex(random_bytes(8)),
        ], $extraParams);

        $separator = str_contains($authEndpoint, '?') ? '&' : '?';

        return $authEndpoint.$separator.http_build_query($params);
    }

    private function find(string $oauthProviderId): ?OauthProvider
    {
        $env = app(Environment::class);

        return OauthProvider::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauthProviderId)
            ->first();
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => $code, 'message' => $message, 'long_message' => $message, 'meta' => []]],
            'trace_id' => null,
        ], $status);
    }
}
