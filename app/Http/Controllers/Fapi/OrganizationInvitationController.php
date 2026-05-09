<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Events\Organizations\OrganizationInvitationCreated;
use App\Events\Organizations\OrganizationInvitationRevoked;
use App\Http\Resources\ClientResource;
use App\Http\Resources\OrganizationInvitationResource;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\Tickets\TicketIssuer;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FAPI per-org invitation surface (PLAN §4.4 / OA-3 / AU-7). Mirrors
 * the BAPI shape from AU-4, gated by EnsureOrgPermission middleware.
 *
 *   GET    /v1/organizations/{organization_id}/invitations
 *   POST   /v1/organizations/{organization_id}/invitations
 *   POST   /v1/organizations/{organization_id}/invitations/bulk
 *   POST   /v1/organizations/{organization_id}/invitations/{invitation_id}/revoke
 */
final class OrganizationInvitationController
{
    public const DEFAULT_TTL_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(private readonly TicketIssuer $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $org = app(Organization::class);

        $query = OrganizationInvitation::query()->where('organization_id', $org->id);
        if ($request->has('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }
        $query->latest('created_at');

        $limit = max(1, min(500, (int) $request->input('limit', 10)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->with('role')->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OrganizationInvitation $i) => OrganizationInvitationResource::from($i, $this->ticketUrl($i)))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);
        $user = app(User::class);

        $err = $this->validatePayload($env, $org, $request->all());
        if (is_array($err)) {
            return $this->error($err['status'], $err['code'], $err['message']);
        }

        [$invitation, $url] = $this->createOne($env, $org, $user, $request->all());
        OrganizationInvitationCreated::dispatch($invitation, $url);

        return $this->envelope(OrganizationInvitationResource::from($invitation->fresh()->load('role'), $url), 201);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $org = app(Organization::class);
        $user = app(User::class);
        $rows = (array) $request->input('invitations', []);
        if (count($rows) === 0 || count($rows) > 100) {
            return $this->error(422, 'form_param_value_invalid', 'invitations must contain between 1 and 100 entries.');
        }

        foreach ($rows as $i => $row) {
            $err = $this->validatePayload($env, $org, (array) $row);
            if (is_array($err)) {
                return $this->error($err['status'], $err['code'], "Row {$i}: ".$err['message']);
            }
        }

        try {
            $created = DB::transaction(function () use ($env, $org, $user, $rows): array {
                $out = [];
                foreach ($rows as $row) {
                    $out[] = $this->createOne($env, $org, $user, (array) $row);
                }

                return $out;
            });
        } catch (Throwable $e) {
            return $this->error(422, 'bulk_invitation_failed', $e->getMessage());
        }

        foreach ($created as [$invitation, $url]) {
            OrganizationInvitationCreated::dispatch($invitation, $url);
        }

        return $this->envelope([
            'object' => 'bulk_organization_invitation_result',
            'total' => count($created),
            'created' => count($created),
            'data' => array_map(
                fn (array $entry) => OrganizationInvitationResource::from($entry[0]->fresh()->load('role'), $entry[1]),
                $created,
            ),
        ], 201);
    }

    public function revoke(Request $request): JsonResponse
    {
        $org = app(Organization::class);
        $invitationId = (string) $request->route('invitation_id');

        $invitation = OrganizationInvitation::query()
            ->where('organization_id', $org->id)
            ->where('id', $invitationId)
            ->first();
        if ($invitation === null) {
            return $this->error(404, 'organization_invitation_not_found', 'No invitation matches that id in this organization.');
        }

        if ($invitation->status === OrganizationInvitation::STATUS_PENDING) {
            DB::transaction(function () use ($invitation, $org): void {
                $invitation->forceFill(['status' => OrganizationInvitation::STATUS_REVOKED])->save();
                $org->decrement('pending_invitations_count');
            });
            OrganizationInvitationRevoked::dispatch($invitation->fresh());
        }

        return $this->envelope(OrganizationInvitationResource::from($invitation->fresh()->load('role')));
    }

    /* -------------------- helpers -------------------- */

    /**
     * @return array{0: OrganizationInvitation, 1: string}
     */
    private function createOne(Environment $env, Organization $org, User $user, array $data): array
    {
        $email = strtolower((string) ($data['email_address'] ?? ''));
        $expiresAt = isset($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : now()->addSeconds(self::DEFAULT_TTL_SECONDS);

        $role = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('key', (string) $data['role'])
            ->first();

        $invitation = OrganizationInvitation::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'email_address' => $email,
            'role_id' => $role?->id,
            'inviter_user_id' => $user->id,
            'redirect_url' => $data['redirect_url'] ?? null,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'public_metadata' => $data['public_metadata'] ?? [],
            'expires_at' => $expiresAt,
        ]);
        $org->increment('pending_invitations_count');

        $jwt = $this->tickets->issue($env, max(60, $expiresAt->getTimestamp() - now()->getTimestamp()), [
            'sub' => $email,
            'sid' => $invitation->id,
            'purpose' => 'organization_invitation',
            'metadata' => is_array($invitation->public_metadata) ? $invitation->public_metadata : [],
            'redirect_url' => $invitation->redirect_url,
        ]);

        return [$invitation, $this->ticketUrlWithJwt($env, $jwt, $invitation->redirect_url)];
    }

    /**
     * @return array{status:int,code:string,message:string}|null
     */
    private function validatePayload(Environment $env, Organization $org, array $data): ?array
    {
        $email = strtolower((string) ($data['email_address'] ?? ''));
        if ($email === '' || ! str_contains($email, '@')) {
            return ['status' => 422, 'code' => 'form_param_format_invalid', 'message' => 'email_address is required and must be a valid email.'];
        }
        $roleKey = (string) ($data['role'] ?? '');
        if ($roleKey === '') {
            return ['status' => 422, 'code' => 'form_param_nil', 'message' => 'role is required.'];
        }
        $role = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('key', $roleKey)
            ->first();
        if ($role === null) {
            return ['status' => 422, 'code' => 'form_param_value_invalid', 'message' => 'role is not a known role key in this environment.'];
        }
        $duplicate = OrganizationInvitation::query()
            ->where('organization_id', $org->id)
            ->where('email_address', $email)
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->exists();
        if ($duplicate) {
            return ['status' => 409, 'code' => 'organization_invitation_already_exists', 'message' => 'A pending invitation for this email already exists.'];
        }

        return null;
    }

    private function ticketUrl(OrganizationInvitation $invitation): string
    {
        $env = app(Environment::class);
        $ttl = $invitation->expires_at !== null
            ? max(60, $invitation->expires_at->getTimestamp() - now()->getTimestamp())
            : self::DEFAULT_TTL_SECONDS;
        $jwt = $this->tickets->issue($env, $ttl, [
            'sub' => strtolower($invitation->email_address),
            'sid' => $invitation->id,
            'purpose' => 'organization_invitation',
            'metadata' => is_array($invitation->public_metadata) ? $invitation->public_metadata : [],
            'redirect_url' => $invitation->redirect_url,
        ]);

        return $this->ticketUrlWithJwt($env, $jwt, $invitation->redirect_url);
    }

    private function ticketUrlWithJwt(Environment $env, string $jwt, ?string $redirect): string
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
