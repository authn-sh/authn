<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

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
 * FAPI per-org SCIM management surface — what `<OrganizationProfile />`'s
 * Directory Sync section drives. Gated by `org:sys_provisioning:manage`
 * via `EnsureOrgPermission` (registered on each route).
 *
 *   GET    /v1/organizations/{org_id}/scim/tokens
 *   POST   /v1/organizations/{org_id}/scim/tokens
 *   POST   /v1/organizations/{org_id}/scim/tokens/{token_id}/revoke
 *   GET    /v1/organizations/{org_id}/scim/attribute-mappings
 *   PUT    /v1/organizations/{org_id}/scim/attribute-mappings
 *   GET    /v1/organizations/{org_id}/scim/endpoint
 *
 * The token plaintext is returned **only** on the issue (POST) response;
 * subsequent list / show responses expose only the visible 12-char prefix
 * registered with the token at create time.
 */
final class OrganizationScimController
{
    public function listTokens(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);

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

    public function issueToken(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);
        $user = app(User::class);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:1|max:100',
            'expires_at' => 'nullable|date',
            'enterprise_connection_id' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $data = $validator->validated();

        $minted = ScimToken::mintPlaintext();
        $row = ScimToken::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'enterprise_connection_id' => $data['enterprise_connection_id'] ?? null,
            'hashed_token' => $minted['hash'],
            'prefix' => $minted['prefix'],
            'name' => $data['name'],
            'created_by_user_id' => $user->id,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        Log::info('audit:fapi.scim_token.issued', [
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'scim_token_id' => $row->id,
            'created_by_user_id' => $user->id,
        ]);
        app(Emitter::class)->emit('scimToken.issued', $this->tokenShape($row->fresh()), $env);

        // The plaintext rides on the spec's `token` property of the
        // ScimToken shape (writeOnly, populated only on this endpoint).
        return response()->json(array_merge(
            $this->tokenShape($row->fresh()),
            ['token' => $minted['plaintext']],
        ), 201)->header('Cache-Control', 'no-store');
    }

    public function revokeToken(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);
        $id = (string) $request->route('token_id');
        $row = ScimToken::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->first();
        if ($row === null) {
            return $this->error(404, 'scim_token_not_found', "ScimToken {$id} not found.");
        }
        $row->revoke();

        Log::info('audit:fapi.scim_token.revoked', [
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'scim_token_id' => $row->id,
        ]);
        app(Emitter::class)->emit('scimToken.revoked', $this->tokenShape($row->fresh()), $env);

        return response()->json($this->tokenShape($row->fresh()))->header('Cache-Control', 'no-store');
    }

    public function showAttributeMappings(Request $request): JsonResponse
    {
        $org = app(Organization::class);
        $resolved = ScimAttributeMapping::resolveFor($org);

        // Spec shape (OA-7): `{ organization_id, mapping: { <source>: <target> } }`.
        $mapping = [];
        foreach ($resolved as $source => $entry) {
            $mapping[$source] = $entry['target'];
        }

        return response()->json([
            'organization_id' => $org->id,
            'mapping' => $mapping,
        ])->header('Cache-Control', 'no-store');
    }

    public function replaceAttributeMappings(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);

        $validator = Validator::make($request->all(), [
            'mapping' => 'present|array',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $mappingInput = (array) $request->input('mapping', []);

        // Atomic replace: drop existing per-org overrides, write the new set.
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

        return $this->showAttributeMappings($request);
    }

    public function showEndpoint(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        return response()->json([
            'endpoint_url' => Url::fapi($env, '/scim/v2'),
            'users_url' => Url::fapi($env, '/scim/v2/Users'),
            'groups_url' => Url::fapi($env, '/scim/v2/Groups'),
        ])->header('Cache-Control', 'no-store');
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
            'name' => $row->name,
            'prefix' => $row->prefix,
            'revoked_at' => $row->revoked_at?->getTimestampMs(),
            'created_at' => $row->created_at?->getTimestampMs(),
        ];
    }

    /**
     * @param  array<string, array{target: string, transform: ?string}>  $resolved
     * @return list<array{source_attribute: string, target_attribute: string, transform: ?string}>
     */
    private function shapeResolved(array $resolved): array
    {
        $out = [];
        foreach ($resolved as $source => $entry) {
            $out[] = [
                'source_attribute' => $source,
                'target_attribute' => $entry['target'],
                'transform' => $entry['transform'],
            ];
        }

        return $out;
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
