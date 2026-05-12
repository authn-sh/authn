<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Models\Organization;
use App\Models\ScimToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SCIM 2.0 Groups surface. authn.sh's group concept is an
 * `OrganizationMembership` aggregate keyed on `Role.key` — a single
 * "Group" per (organization, role). v0.6 ships a read-only listing
 * surface; PATCH/PUT operations on memberships land in v0.7 alongside
 * the role-self-service work.
 */
final class GroupsController
{
    public function index(Request $request): JsonResponse
    {
        $token = app(ScimToken::class);
        $organization = $token->organization_id !== null
            ? Organization::query()->withoutGlobalScopes()->where('id', $token->organization_id)->first()
            : null;
        if ($organization === null) {
            return $this->scimResponse([
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
                'totalResults' => 0,
                'startIndex' => 1,
                'itemsPerPage' => 0,
                'Resources' => [],
            ]);
        }

        $startIndex = max(1, (int) $request->query('startIndex', '1'));
        $count = min(200, max(0, (int) $request->query('count', '20')));

        $rows = \DB::table('organization_memberships')
            ->join('roles', 'organization_memberships.role_id', '=', 'roles.id')
            ->where('organization_memberships.organization_id', $organization->id)
            ->select('roles.id as role_id', 'roles.key as role_key', 'roles.name as role_name', 'organization_memberships.user_id')
            ->get()
            ->groupBy('role_id');

        $resources = $rows->slice($startIndex - 1, $count)->map(function ($members, $roleId) {
            $first = $members->first();

            return [
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'],
                'id' => 'role_'.$roleId.'__org_grp',
                'displayName' => $first->role_name ?? $first->role_key,
                'members' => $members->map(fn ($m) => ['value' => $m->user_id, 'type' => 'User'])->all(),
            ];
        })->values()->all();

        return $this->scimResponse([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $rows->count(),
            'startIndex' => $startIndex,
            'itemsPerPage' => count($resources),
            'Resources' => $resources,
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');

        return $this->scimError(404, 'notFound', "Group {$id} not found.");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function scimResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Content-Type' => 'application/scim+json']);
    }

    private function scimError(int $status, string $scimType, string $detail): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'scimType' => $scimType,
            'status' => (string) $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/scim+json']);
    }
}
