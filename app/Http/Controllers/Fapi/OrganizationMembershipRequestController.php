<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Events\Organizations\OrganizationMembershipCreated;
use App\Events\Organizations\OrganizationMembershipRequestApproved;
use App\Events\Organizations\OrganizationMembershipRequestRejected;
use App\Http\Resources\ClientResource;
use App\Http\Resources\OrganizationMembershipRequestResource;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationMembershipRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FAPI per-org admin surface for membership requests (PLAN §4.4 / OA-3 / AU-7).
 *
 *   GET    /v1/organizations/{organization_id}/membership_requests
 *   POST   /v1/organizations/{organization_id}/membership_requests/{request_id}/accept
 *   POST   /v1/organizations/{organization_id}/membership_requests/{request_id}/reject
 */
final class OrganizationMembershipRequestController
{
    public function index(Request $request): JsonResponse
    {
        $org = app(Organization::class);
        $query = OrganizationMembershipRequest::query()->where('organization_id', $org->id);
        if ($request->has('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }
        $query->latest('created_at');

        $limit = max(1, min(500, (int) $request->input('limit', 10)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OrganizationMembershipRequest $r) => OrganizationMembershipRequestResource::from($r))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);
        $requestId = (string) $request->route('request_id');

        $row = OrganizationMembershipRequest::query()
            ->where('organization_id', $org->id)
            ->where('id', $requestId)
            ->first();
        if ($row === null) {
            return $this->error(404, 'organization_membership_request_not_found', 'No membership request matches that id.');
        }
        if ($row->status !== OrganizationMembershipRequest::STATUS_PENDING) {
            return $this->error(409, 'organization_membership_request_not_pending', 'This request has already been resolved.');
        }

        $defaultRole = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('is_default', true)
            ->first();
        if ($defaultRole === null) {
            return $this->error(422, 'role_no_default', 'Environment has no default role.');
        }

        $duplicateMembership = OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $row->user_id)
            ->exists();
        if ($duplicateMembership) {
            return $this->error(409, 'organization_membership_already_exists', 'User is already a member of this organization.');
        }

        $membership = DB::transaction(function () use ($env, $org, $row, $defaultRole): OrganizationMembership {
            $row->forceFill(['status' => OrganizationMembershipRequest::STATUS_ACCEPTED])->save();
            $membership = OrganizationMembership::create([
                'environment_id' => $env->id,
                'organization_id' => $org->id,
                'user_id' => $row->user_id,
                'role_id' => $defaultRole->id,
            ]);
            $org->increment('members_count');

            return $membership;
        });

        OrganizationMembershipRequestApproved::dispatch($row->fresh());
        OrganizationMembershipCreated::dispatch($membership);

        return $this->envelope(OrganizationMembershipRequestResource::from($row->fresh()));
    }

    public function reject(Request $request): JsonResponse
    {
        $org = app(Organization::class);
        $requestId = (string) $request->route('request_id');

        $row = OrganizationMembershipRequest::query()
            ->where('organization_id', $org->id)
            ->where('id', $requestId)
            ->first();
        if ($row === null) {
            return $this->error(404, 'organization_membership_request_not_found', 'No membership request matches that id.');
        }
        if ($row->status !== OrganizationMembershipRequest::STATUS_PENDING) {
            return $this->error(409, 'organization_membership_request_not_pending', 'This request has already been resolved.');
        }

        $row->forceFill(['status' => OrganizationMembershipRequest::STATUS_REVOKED])->save();
        OrganizationMembershipRequestRejected::dispatch($row->fresh());

        return $this->envelope(OrganizationMembershipRequestResource::from($row->fresh()));
    }

    /* -------------------- helpers -------------------- */

    private function envelope(mixed $response, int $status = 200): JsonResponse
    {
        $client = app()->bound(Client::class) ? app(Client::class) : null;

        return response()->json([
            'response' => $response,
            'client' => ClientResource::from($client?->fresh()),
        ], $status)->header('Cache-Control', 'no-store');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ], $status);
    }
}
