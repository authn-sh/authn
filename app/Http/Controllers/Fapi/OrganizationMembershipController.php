<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Events\Organizations\OrganizationInvitationCreated;
use App\Events\Organizations\OrganizationMembershipDeleted;
use App\Events\Organizations\OrganizationMembershipUpdated;
use App\Http\Resources\ClientResource;
use App\Http\Resources\OrganizationInvitationResource;
use App\Http\Resources\OrganizationMembershipResource;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\RoleSeeder;
use App\Services\Tickets\TicketIssuer;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FAPI organization-memberships surface (PLAN §4.4, OA-3).
 *
 *   GET    /v1/organizations/{organization_id}/memberships
 *   POST   /v1/organizations/{organization_id}/memberships    (issues an invitation)
 *   PATCH  /v1/organizations/{organization_id}/memberships/{user_id}
 *   DELETE /v1/organizations/{organization_id}/memberships/{user_id}
 */
final class OrganizationMembershipController
{
    public const INVITATION_TTL_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(private readonly TicketIssuer $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $org = app(Organization::class);

        $query = OrganizationMembership::query()->where('organization_id', $org->id);
        $orderBy = (string) $request->input('order_by', '-created_at');
        $direction = str_starts_with($orderBy, '-') ? 'desc' : 'asc';
        $column = ltrim($orderBy, '-+');
        $query->orderBy(in_array($column, ['created_at', 'updated_at'], true) ? $column : 'created_at', $direction);

        $limit = max(1, min(500, (int) $request->input('limit', 10)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->with(['user', 'role.permissions'])->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OrganizationMembership $m) => OrganizationMembershipResource::from($m))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);
        $user = app(User::class);

        $email = $request->input('email_address');
        $roleKey = $request->input('role');
        if (! is_string($email) || $email === '') {
            return $this->error(422, 'form_param_nil', 'email_address is required.');
        }
        if (! is_string($roleKey) || $roleKey === '') {
            return $this->error(422, 'form_param_nil', 'role is required.');
        }

        $role = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('key', $roleKey)
            ->first();
        if ($role === null) {
            return $this->error(422, 'form_param_value_invalid', 'role is not a known role key in this environment.');
        }

        $email = strtolower($email);
        $duplicate = OrganizationInvitation::query()
            ->where('organization_id', $org->id)
            ->where('email_address', $email)
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'organization_invitation_already_exists', 'A pending invitation for this email already exists.');
        }

        $expiresAt = now()->addSeconds(self::INVITATION_TTL_SECONDS);

        $invitation = DB::transaction(function () use ($env, $org, $user, $email, $role, $expiresAt, $request): OrganizationInvitation {
            $row = OrganizationInvitation::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'organization_id' => $org->id,
                'email_address' => $email,
                'role_id' => $role->id,
                'inviter_user_id' => $user->id,
                'redirect_url' => $request->input('redirect_url'),
                'status' => OrganizationInvitation::STATUS_PENDING,
                'public_metadata' => $request->input('public_metadata') ?? [],
                'expires_at' => $expiresAt,
            ]);
            $org->increment('pending_invitations_count');

            return $row;
        });

        $jwt = $this->tickets->issue($env, max(60, $expiresAt->getTimestamp() - now()->getTimestamp()), [
            'sub' => $email,
            'sid' => $invitation->id,
            'purpose' => 'organization_invitation',
            'metadata' => is_array($invitation->public_metadata) ? $invitation->public_metadata : [],
            'redirect_url' => $invitation->redirect_url,
        ]);
        $url = $this->ticketUrl($env, $jwt, $invitation->redirect_url);

        OrganizationInvitationCreated::dispatch($invitation, $url);

        return $this->envelope(OrganizationInvitationResource::from($invitation->fresh()->load('role'), $url), 201);
    }

    public function update(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);
        $userId = (string) $request->route('user_id');

        $membership = OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->first();
        if ($membership === null) {
            return $this->error(404, 'organization_membership_not_found', 'No membership matches that user in this organization.');
        }

        $roleKey = $request->input('role');
        if (! is_string($roleKey) || $roleKey === '') {
            return $this->error(422, 'form_param_nil', 'role is required.');
        }
        $role = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('key', $roleKey)
            ->first();
        if ($role === null) {
            return $this->error(422, 'form_param_value_invalid', 'role is not a known role key in this environment.');
        }

        if (
            $membership->role?->key === RoleSeeder::ROLE_ADMIN
            && $role->key !== RoleSeeder::ROLE_ADMIN
            && $this->countAdmins($org) <= 1
        ) {
            return $this->error(422, 'organization_last_admin', 'Cannot demote the last admin of an organization.');
        }

        $membership->role_id = $role->id;
        $membership->save();

        OrganizationMembershipUpdated::dispatch($membership->fresh());

        return $this->envelope(OrganizationMembershipResource::from(
            $membership->fresh()->load(['user', 'role.permissions']),
        ));
    }

    public function destroy(Request $request): JsonResponse
    {
        $org = app(Organization::class);
        $userId = (string) $request->route('user_id');
        $membership = OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->first();
        if ($membership === null) {
            return $this->error(404, 'organization_membership_not_found', 'No membership matches that user in this organization.');
        }

        if (
            $membership->role?->key === RoleSeeder::ROLE_ADMIN
            && $this->countAdmins($org) <= 1
        ) {
            return $this->error(422, 'organization_last_admin', 'Cannot remove the last admin of an organization.');
        }

        DB::transaction(function () use ($org, $membership): void {
            $membership->delete();
            $org->decrement('members_count');
        });

        OrganizationMembershipDeleted::dispatch($membership);

        return $this->envelope([
            'object' => 'deleted_object',
            'id' => $membership->id,
            'deleted' => true,
        ]);
    }

    /* -------------------- helpers -------------------- */

    private function countAdmins(Organization $org): int
    {
        return OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->whereHas('role', fn ($q) => $q->where('key', RoleSeeder::ROLE_ADMIN))
            ->count();
    }

    private function ticketUrl(Environment $env, string $jwt, ?string $redirect): string
    {
        $base = Url::fapi($env, '');
        $query = http_build_query(array_filter([
            '__authn_ticket' => $jwt,
            '__authn_status' => 'sign_up',
            'redirect_url' => $redirect,
        ], fn ($v) => $v !== null && $v !== ''));

        return rtrim($base, '/').'/sign-up?'.$query;
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
