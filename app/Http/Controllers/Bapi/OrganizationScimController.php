<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Models\Environment;
use App\Models\Organization;
use App\Models\ScimAttributeMapping;
use App\Models\ScimToken;
use App\Models\User;
use App\Support\Url;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * BAPI SCIM admin surface — operator-scoped mirror of the v0.6 FAPI per-org
 * SCIM management endpoints. Authentication is bearer-secret; the FAPI
 * `org:sys_provisioning:manage` permission check is intentionally absent
 * since BAPI calls are operator-scoped, not member-scoped (operators may
 * act on any org in their env).
 *
 *   GET    /v1/organizations/{org_id}/scim/tokens
 *   POST   /v1/organizations/{org_id}/scim/tokens
 *   POST   /v1/organizations/{org_id}/scim/tokens/{token_id}/revoke
 *   GET    /v1/organizations/{org_id}/scim/attribute-mappings
 *   PUT    /v1/organizations/{org_id}/scim/attribute-mappings
 *   GET    /v1/organizations/{org_id}/scim/endpoint
 */
final class OrganizationScimController
{
    public function listTokens(Request $request, string $organizationId): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->findOrganization($env, $organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', "Organization {$organizationId} not found.");
        }

        $rows = ScimToken::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('organization_id', $org->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ScimToken $t) => $this->tokenShape($t))->all(),
            'total_count' => $rows->count(),
        ])->header('Cache-Control', 'no-store');
    }

    public function issueToken(Request $request, string $organizationId): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->findOrganization($env, $organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', "Organization {$organizationId} not found.");
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:1|max:100',
            'expires_at' => 'nullable|date',
            'enterprise_connection_id' => 'nullable|string',
            'created_by_user_id' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $data = $validator->validated();

        // Operator-scoped: BAPI callers don't ride a session, so `created_by`
        // is optional. When supplied, it must point at a user in this env;
        // otherwise we fall back to the first existing user (so the FK
        // constraint can be honoured without forcing a synthetic user).
        $createdById = $this->resolveCreatedBy($env, $data['created_by_user_id'] ?? null);
        if ($createdById === null) {
            return $this->error(422, 'created_by_user_id_required',
                'No user exists in this environment to attribute the token to; pass `created_by_user_id` explicitly.');
        }

        $minted = ScimToken::mintPlaintext();
        $row = ScimToken::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'enterprise_connection_id' => $data['enterprise_connection_id'] ?? null,
            'hashed_token' => $minted['hash'],
            'prefix' => $minted['prefix'],
            'name' => $data['name'],
            'created_by_user_id' => $createdById,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        Log::info('audit:bapi.scim_token.issued', [
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'scim_token_id' => $row->id,
            'created_by_user_id' => $createdById,
        ]);
        app(Emitter::class)->emit('scimToken.issued', $this->tokenShape($row->fresh()), $env);

        return response()->json(array_merge(
            $this->tokenShape($row->fresh()),
            ['token' => $minted['plaintext']],
        ), 201)->header('Cache-Control', 'no-store');
    }

    public function revokeToken(Request $request, string $organizationId, string $tokenId): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->findOrganization($env, $organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', "Organization {$organizationId} not found.");
        }

        $row = ScimToken::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('organization_id', $org->id)
            ->where('id', $tokenId)
            ->first();
        if ($row === null) {
            return $this->error(404, 'scim_token_not_found', "ScimToken {$tokenId} not found.");
        }
        $row->revoke();

        Log::info('audit:bapi.scim_token.revoked', [
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'scim_token_id' => $row->id,
        ]);
        app(Emitter::class)->emit('scimToken.revoked', $this->tokenShape($row->fresh()), $env);

        return response()->json($this->tokenShape($row->fresh()))->header('Cache-Control', 'no-store');
    }

    public function showAttributeMappings(Request $request, string $organizationId): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->findOrganization($env, $organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', "Organization {$organizationId} not found.");
        }

        $resolved = ScimAttributeMapping::resolveFor($org);
        $mapping = [];
        foreach ($resolved as $source => $entry) {
            $mapping[$source] = $entry['target'];
        }

        return response()->json([
            'organization_id' => $org->id,
            'mapping' => $mapping,
        ])->header('Cache-Control', 'no-store');
    }

    public function replaceAttributeMappings(Request $request, string $organizationId): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->findOrganization($env, $organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', "Organization {$organizationId} not found.");
        }

        $validator = Validator::make($request->all(), [
            'mapping' => 'present|array',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $mappingInput = (array) $request->input('mapping', []);

        ScimAttributeMapping::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('organization_id', $org->id)
            ->delete();
        foreach ($mappingInput as $source => $target) {
            if (! is_string($source) || ! is_string($target)) {
                continue;
            }
            ScimAttributeMapping::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'organization_id' => $org->id,
                'source_attribute' => $source,
                'target_attribute' => $target,
            ]);
        }

        return $this->showAttributeMappings($request, $organizationId);
    }

    public function showEndpoint(Request $request, string $organizationId): JsonResponse
    {
        $env = app(Environment::class);
        $org = $this->findOrganization($env, $organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', "Organization {$organizationId} not found.");
        }

        return response()->json([
            'organization_id' => $org->id,
            'endpoint_url' => Url::fapi($env, '/scim/v2'),
            'users_url' => Url::fapi($env, '/scim/v2/Users'),
            'groups_url' => Url::fapi($env, '/scim/v2/Groups'),
        ])->header('Cache-Control', 'no-store');
    }

    private function findOrganization(Environment $env, string $organizationId): ?Organization
    {
        return Organization::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $organizationId)
            ->first();
    }

    private function resolveCreatedBy(Environment $env, ?string $requested): ?string
    {
        if ($requested !== null && $requested !== '') {
            $exists = User::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->where('id', $requested)
                ->exists();

            return $exists ? $requested : null;
        }

        // Fall back to any existing user in the env so the FK constraint
        // is honoured without forcing operators to supply one explicitly.
        return User::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('id')
            ->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenShape(ScimToken $row): array
    {
        return [
            'object' => 'scim_token',
            'id' => $row->id,
            'organization_id' => $row->organization_id,
            'enterprise_connection_id' => $row->enterprise_connection_id,
            'name' => $row->name,
            'prefix' => $row->prefix,
            'expires_at' => $row->expires_at?->getTimestampMs(),
            'revoked_at' => $row->revoked_at?->getTimestampMs(),
            'last_used_at' => $row->last_used_at?->getTimestampMs(),
            'created_at' => $row->created_at?->getTimestampMs(),
        ];
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => $code, 'message' => $message, 'long_message' => $message]],
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
                $flat[] = [
                    'code' => 'form_param_invalid',
                    'message' => $message,
                    'long_message' => $message,
                    'meta' => ['param' => $field],
                ];
            }
        }

        return response()->json(['errors' => $flat], 422)->header('Cache-Control', 'no-store');
    }
}
