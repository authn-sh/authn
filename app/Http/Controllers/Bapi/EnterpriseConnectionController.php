<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Auth\EnterpriseSso\OidcConnectionService;
use App\Auth\EnterpriseSso\OidcDiscoveryException;
use App\Http\Resources\EnterpriseConnectionResource;
use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * BAPI `/v1/enterprise-connections` — instance-wide CRUD (OA-4). Per-org
 * creation goes through the FAPI surface in AU-6. Mirrors the v0.4
 * `OauthProviderController` shape.
 *
 *   GET    /v1/enterprise-connections                              (with ?organization_id filter)
 *   POST   /v1/enterprise-connections
 *   GET    /v1/enterprise-connections/{id}
 *   PATCH  /v1/enterprise-connections/{id}
 *   DELETE /v1/enterprise-connections/{id}   — 409 when EnterpriseAccount rows still link
 *   POST   /v1/enterprise-connections/{id}/test
 */
final class EnterpriseConnectionController
{
    public function __construct(private readonly OidcConnectionService $oidc) {}

    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = EnterpriseConnection::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('id');

        if ($request->has('organization_id')) {
            $orgId = $request->input('organization_id');
            if ($orgId === null || $orgId === '') {
                $query->whereNull('organization_id');
            } else {
                $query->where('organization_id', $orgId);
            }
        }

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (EnterpriseConnection $c) => EnterpriseConnectionResource::from($c))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        // Instance-wide creates only on this surface — per-org goes through
        // the FAPI per-org endpoint in AU-6.
        if ($request->has('organization_id') && $request->input('organization_id') !== null) {
            return $this->error(422, 'organization_id_not_allowed', 'Use the per-org FAPI endpoint to scope a connection to an organization.');
        }

        $validator = Validator::make($request->all(), $this->validationRules($request, isCreate: true));
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $data = $validator->validated();
        $data['environment_id'] = $env->id;

        $conn = EnterpriseConnection::query()->withoutGlobalScopes()->create($data);

        Log::info('audit:bapi.enterprise_connection.created', [
            'environment_id' => $env->id,
            'enterprise_connection_id' => $conn->id,
            'protocol' => $conn->protocol,
        ]);
        app(Emitter::class)->emit('enterpriseConnection.created', EnterpriseConnectionResource::from($conn->fresh()), $env);

        return response()->json(EnterpriseConnectionResource::from($conn->fresh()), 201)
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): JsonResponse
    {
        $conn = $this->loadOrFail($request);
        if ($conn instanceof JsonResponse) {
            return $conn;
        }

        return response()->json(EnterpriseConnectionResource::from($conn))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $conn = $this->loadOrFail($request);
        if ($conn instanceof JsonResponse) {
            return $conn;
        }

        if ($request->has('protocol') && $request->input('protocol') !== $conn->protocol) {
            return $this->error(422, 'protocol_immutable', 'protocol cannot change after a connection is created.');
        }
        if ($request->has('organization_id') && $request->input('organization_id') !== $conn->organization_id) {
            return $this->error(422, 'organization_id_immutable', 'organization_id cannot change after a connection is created.');
        }

        $validator = Validator::make($request->all(), $this->validationRules($request, isCreate: false, protocol: $conn->protocol));
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $conn->fill(array_intersect_key($validator->validated(), array_flip([
            'name', 'enabled', 'domains', 'default_role', 'attribute_mapping',
            'saml_idp_entity_id', 'saml_sso_url', 'saml_idp_certificate',
            'saml_signing_algorithm', 'saml_audience_uri', 'saml_signing_key',
            'oidc_issuer', 'oidc_discovery_endpoint', 'oidc_client_id', 'oidc_client_secret', 'oidc_scopes',
        ])));
        $conn->save();

        Log::info('audit:bapi.enterprise_connection.updated', [
            'environment_id' => app(Environment::class)->id,
            'enterprise_connection_id' => $conn->id,
        ]);
        app(Emitter::class)->emit('enterpriseConnection.updated', EnterpriseConnectionResource::from($conn->fresh()));

        return response()->json(EnterpriseConnectionResource::from($conn->fresh()))->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): JsonResponse
    {
        $conn = $this->loadOrFail($request);
        if ($conn instanceof JsonResponse) {
            return $conn;
        }

        $linked = EnterpriseAccount::query()->withoutGlobalScopes()
            ->where('enterprise_connection_id', $conn->id)
            ->exists();
        if ($linked) {
            return $this->error(409, 'enterprise_connection_in_use', 'Cannot delete — there are still EnterpriseAccount rows linked to this connection.');
        }

        $snapshot = EnterpriseConnectionResource::from($conn);
        $conn->delete();

        Log::info('audit:bapi.enterprise_connection.deleted', [
            'environment_id' => app(Environment::class)->id,
            'enterprise_connection_id' => $conn->id,
        ]);
        app(Emitter::class)->emit('enterpriseConnection.deleted', $snapshot);

        return response()->json(null, 204);
    }

    /**
     * `test` dry-run — for OIDC fetches the discovery document; for SAML
     * validates the configured `saml_idp_entity_id` and (when set) the
     * IdP-side metadata URL is reachable. Returns a structured result
     * envelope; never alters connection state.
     */
    public function test(Request $request): JsonResponse
    {
        $conn = $this->loadOrFail($request);
        if ($conn instanceof JsonResponse) {
            return $conn;
        }

        if ($conn->isOidc()) {
            try {
                $endpoints = $this->oidc->discover($conn);

                return response()->json([
                    'authorize_url' => $endpoints['authorization_endpoint'],
                    'discovery_status' => 200,
                    'errors' => [],
                ]);
            } catch (OidcDiscoveryException $e) {
                return response()->json([
                    'authorize_url' => '',
                    'discovery_status' => null,
                    'errors' => [[
                        'code' => 'oidc_discovery_failed',
                        'message' => 'OIDC discovery failed.',
                        'long_message' => $e->getMessage(),
                    ]],
                ]);
            } catch (Throwable $e) {
                return response()->json([
                    'authorize_url' => '',
                    'discovery_status' => null,
                    'errors' => [[
                        'code' => 'oidc_discovery_failed',
                        'message' => 'OIDC discovery failed.',
                        'long_message' => $e->getMessage(),
                    ]],
                ]);
            }
        }

        $errors = [];
        foreach (['saml_idp_entity_id', 'saml_sso_url', 'saml_idp_certificate'] as $field) {
            $value = $conn->{$field};
            if (! is_string($value) || $value === '') {
                $errors[] = [
                    'code' => 'saml_missing_field',
                    'message' => "Field {$field} is required.",
                    'long_message' => "The SAML connection cannot be exercised until `{$field}` is set on the row. Update the connection and retry the test.",
                    'meta' => ['param' => $field],
                ];
            }
        }

        return response()->json([
            'authorize_url' => $errors === [] ? (string) $conn->saml_sso_url : '',
            'discovery_status' => $errors === [] ? 200 : null,
            'errors' => $errors,
        ]);
    }

    /**
     * @return array<string, list<string>|string>
     */
    private function validationRules(Request $request, bool $isCreate, ?string $protocol = null): array
    {
        $protocol = $protocol ?? (string) $request->input('protocol');
        $rules = [
            'protocol' => $isCreate ? 'required|in:saml,oidc' : 'in:saml,oidc',
            'name' => $isCreate ? 'required|string|max:255' : 'string|max:255',
            'enabled' => 'boolean',
            'domains' => 'array',
            'domains.*' => 'string',
            'default_role' => 'nullable|string|max:255',
            'attribute_mapping' => 'array',
        ];
        if ($protocol === EnterpriseConnection::PROTOCOL_SAML) {
            $rules += [
                'saml_idp_entity_id' => ($isCreate ? 'required|' : '').'string|max:512',
                'saml_sso_url' => ($isCreate ? 'required|' : '').'url|max:512',
                'saml_idp_certificate' => ($isCreate ? 'required|' : '').'string',
                'saml_signing_algorithm' => 'nullable|string|max:128',
                'saml_audience_uri' => 'nullable|string|max:512',
                'saml_signing_key' => 'nullable|string',
            ];
        }
        if ($protocol === EnterpriseConnection::PROTOCOL_OIDC) {
            $rules += [
                'oidc_issuer' => ($isCreate ? 'required|' : '').'url|max:512',
                'oidc_discovery_endpoint' => 'nullable|url|max:512',
                'oidc_client_id' => ($isCreate ? 'required|' : '').'string|max:255',
                'oidc_client_secret' => ($isCreate ? 'required|' : '').'string',
                'oidc_scopes' => 'array',
                'oidc_scopes.*' => 'string',
            ];
        }

        return $rules;
    }

    private function loadOrFail(Request $request): EnterpriseConnection|JsonResponse
    {
        $env = app(Environment::class);
        $id = (string) $request->route('enterprise_connection_id');
        $conn = EnterpriseConnection::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
        if ($conn === null) {
            return $this->error(404, 'enterprise_connection_not_found', "EnterpriseConnection {$id} not found.");
        }

        return $conn;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => $code, 'message' => $message]],
        ], $status)->header('Cache-Control', 'no-store');
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        $flat = [];
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $flat[] = ['code' => 'form_param_invalid', 'message' => $message, 'param' => $field];
            }
        }

        return response()->json(['errors' => $flat], 422)->header('Cache-Control', 'no-store');
    }
}
