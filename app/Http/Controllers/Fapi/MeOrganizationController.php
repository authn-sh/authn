<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Events\Organizations\OrganizationInvitationAccepted;
use App\Events\Organizations\OrganizationMembershipCreated;
use App\Http\Resources\ClientResource;
use App\Http\Resources\OrganizationInvitationResource;
use App\Http\Resources\OrganizationMembershipRequestResource;
use App\Http\Resources\OrganizationMembershipResource;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\OrganizationMembershipRequest;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/v1/me` org-related collections (PLAN §4.4 / OA-3 / AU-7). All endpoints
 * are scoped to the authenticated user.
 *
 *   GET    /v1/me/organization_memberships
 *   GET    /v1/me/organization_invitations
 *   POST   /v1/me/organization_invitations/{invitation_id}/accept
 *   GET    /v1/me/organization_membership_requests
 *   PUT    /v1/me/active_organization
 */
final class MeOrganizationController
{
    public function listMemberships(Request $request): JsonResponse
    {
        $user = app(User::class);

        $query = OrganizationMembership::query()->where('user_id', $user->id);
        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->with(['user', 'role.permissions', 'organization'])
            ->latest('created_at')
            ->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OrganizationMembership $m) => OrganizationMembershipResource::from($m))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function listInvitations(Request $request): JsonResponse
    {
        $user = app(User::class);

        $emails = $this->userEmails($user);
        if ($emails === []) {
            return response()->json(['data' => [], 'total_count' => 0])->header('Cache-Control', 'no-store');
        }

        $query = OrganizationInvitation::query()
            ->whereIn('email_address', $emails);
        if ($request->has('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        } else {
            $query->where('status', OrganizationInvitation::STATUS_PENDING);
        }

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->with('role')->latest('created_at')->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OrganizationInvitation $i) => OrganizationInvitationResource::from($i))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function acceptInvitation(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $user = app(User::class);
        $invitationId = (string) $request->route('invitation_id');

        $invitation = OrganizationInvitation::query()
            ->where('id', $invitationId)
            ->first();
        if ($invitation === null) {
            return $this->error(404, 'organization_invitation_not_found', 'No invitation matches that id.');
        }

        $emails = $this->userEmails($user);
        if (! in_array(strtolower($invitation->email_address), $emails, true)) {
            return $this->error(404, 'organization_invitation_not_found', 'No invitation matches that id.');
        }

        if ($invitation->status === OrganizationInvitation::STATUS_ACCEPTED) {
            return $this->error(409, 'organization_invitation_already_accepted', 'This invitation has already been accepted.');
        }
        if ($invitation->status !== OrganizationInvitation::STATUS_PENDING) {
            return $this->error(409, 'organization_invitation_not_pending', "This invitation is {$invitation->status}.");
        }
        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            return $this->error(410, 'organization_invitation_expired', 'This invitation has expired.');
        }
        if ($invitation->role_id === null) {
            return $this->error(422, 'organization_invitation_role_missing', 'Invitation has no associated role.');
        }

        $duplicate = OrganizationMembership::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('user_id', $user->id)
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'organization_membership_already_exists', 'You are already a member of this organization.');
        }

        $membership = DB::transaction(function () use ($env, $invitation, $user): OrganizationMembership {
            $invitation->forceFill(['status' => OrganizationInvitation::STATUS_ACCEPTED])->save();
            $org = Organization::query()->withoutGlobalScopes()->where('id', $invitation->organization_id)->first();
            $row = OrganizationMembership::create([
                'environment_id' => $env->id,
                'organization_id' => $invitation->organization_id,
                'user_id' => $user->id,
                'role_id' => $invitation->role_id,
            ]);
            if ($org !== null) {
                $org->increment('members_count');
                $org->decrement('pending_invitations_count');
            }

            return $row;
        });

        OrganizationInvitationAccepted::dispatch($invitation->fresh());
        OrganizationMembershipCreated::dispatch($membership);

        return $this->envelope(OrganizationMembershipResource::from(
            $membership->fresh()->load(['user', 'role.permissions']),
        ));
    }

    public function listMembershipRequests(Request $request): JsonResponse
    {
        $user = app(User::class);
        $query = OrganizationMembershipRequest::query()->where('user_id', $user->id);

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->latest('created_at')->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OrganizationMembershipRequest $r) => OrganizationMembershipRequestResource::from($r))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function setActiveOrganization(Request $request): JsonResponse
    {
        $user = app(User::class);
        $session = app(Session::class);

        $organizationId = $request->input('organization_id');
        if ($organizationId === null) {
            if ($session->last_active_organization_id !== null) {
                $session->forceFill([
                    'last_active_organization_id' => null,
                    'token_version' => (int) $session->token_version + 1,
                ])->save();
            }

            return $this->envelope([
                'object' => 'active_organization',
                'organization_id' => null,
            ]);
        }
        if (! is_string($organizationId) || $organizationId === '') {
            return $this->error(422, 'form_param_format_invalid', 'organization_id must be an org_… string or null.');
        }

        $isMember = $user->memberships()
            ->where('organization_id', $organizationId)
            ->exists();
        if (! $isMember) {
            return $this->error(404, 'organization_not_found', 'No membership matches that organization for this user.');
        }

        if ($session->last_active_organization_id !== $organizationId) {
            $session->forceFill([
                'last_active_organization_id' => $organizationId,
                'token_version' => (int) $session->token_version + 1,
            ])->save();
        }

        return $this->envelope([
            'object' => 'active_organization',
            'organization_id' => $organizationId,
        ]);
    }

    /* -------------------- helpers -------------------- */

    /**
     * @return list<string>
     */
    private function userEmails(User $user): array
    {
        return EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->pluck('email_address')
            ->map(fn (string $e) => strtolower($e))
            ->values()
            ->all();
    }

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
