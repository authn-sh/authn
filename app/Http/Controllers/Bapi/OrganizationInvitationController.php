<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Events\Organizations\OrganizationInvitationCreated;
use App\Events\Organizations\OrganizationInvitationRevoked;
use App\Http\Requests\Bapi\Organizations\BulkInvitationRequest;
use App\Http\Requests\Bapi\Organizations\CreateInvitationRequest;
use App\Http\Resources\OrganizationInvitationResource;
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
 * BAPI organization-invitations surface (PLAN §3.1, OA-2).
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

    public function index(Request $request, string $organizationId): JsonResponse
    {
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $query = OrganizationInvitation::query()->where('organization_id', $org->id);
        if ($request->has('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }
        if ($request->has('query')) {
            $needle = '%'.strtolower((string) $request->input('query')).'%';
            $query->whereRaw('LOWER(email_address) LIKE ?', [$needle]);
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

    public function store(CreateInvitationRequest $request, string $organizationId): JsonResponse
    {
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $env = app(Environment::class);
        $payload = $request->all();
        $err = $this->validatePayload($env, $org, $payload);
        if (is_array($err)) {
            return $this->error($err['status'], $err['code'], $err['message']);
        }

        [$invitation, $url] = $this->createOne($env, $org, $payload);

        OrganizationInvitationCreated::dispatch($invitation, $url);

        return response()->json(
            OrganizationInvitationResource::from($invitation->fresh()->load('role'), $url),
            201,
        );
    }

    public function bulkStore(BulkInvitationRequest $request, string $organizationId): JsonResponse
    {
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $env = app(Environment::class);
        $rows = (array) $request->input('invitations');

        // Pre-validate the entire batch so failures roll the whole thing back
        // — partial successes would leave half-baked state for the caller.
        foreach ($rows as $i => $row) {
            $err = $this->validatePayload($env, $org, (array) $row);
            if (is_array($err)) {
                return response()->json([
                    'errors' => [[
                        'code' => $err['code'],
                        'message' => "Row {$i}: ".$err['message'],
                        'long_message' => "Row {$i}: ".$err['message'],
                        'meta' => ['index' => $i],
                    ]],
                    'trace_id' => null,
                ], $err['status']);
            }
        }

        try {
            $created = DB::transaction(function () use ($env, $org, $rows): array {
                $out = [];
                foreach ($rows as $row) {
                    [$invitation, $url] = $this->createOne($env, $org, (array) $row);
                    $out[] = ['invitation' => $invitation, 'url' => $url];
                }

                return $out;
            });
        } catch (Throwable $e) {
            return $this->error(422, 'bulk_invitation_failed', $e->getMessage());
        }

        foreach ($created as $entry) {
            OrganizationInvitationCreated::dispatch($entry['invitation'], $entry['url']);
        }

        return response()->json([
            'object' => 'bulk_organization_invitation_result',
            'total' => count($created),
            'created' => count($created),
            'data' => array_map(
                fn (array $entry) => OrganizationInvitationResource::from($entry['invitation']->fresh()->load('role'), $entry['url']),
                $created,
            ),
        ], 201);
    }

    public function revoke(string $organizationId, string $invitationId): JsonResponse
    {
        $org = $this->findOrganization($organizationId);
        if ($org === null) {
            return $this->error(404, 'organization_not_found', 'No organization matches that id in this environment.');
        }

        $invitation = OrganizationInvitation::query()
            ->where('organization_id', $org->id)
            ->where('id', $invitationId)
            ->first();
        if ($invitation === null) {
            return $this->error(404, 'organization_invitation_not_found', 'No invitation matches that id in this organization.');
        }

        // Idempotent: re-revoking a revoked invitation just returns the row.
        if ($invitation->status === OrganizationInvitation::STATUS_PENDING) {
            DB::transaction(function () use ($invitation, $org): void {
                $invitation->forceFill(['status' => OrganizationInvitation::STATUS_REVOKED])->save();
                $org->decrement('pending_invitations_count');
            });
            OrganizationInvitationRevoked::dispatch($invitation->fresh());
        }

        return response()->json(OrganizationInvitationResource::from($invitation->fresh()->load('role')));
    }

    /* -------------------- helpers -------------------- */

    /**
     * @return array{0: OrganizationInvitation, 1: string}
     */
    private function createOne(Environment $env, Organization $org, array $data): array
    {
        $email = strtolower((string) ($data['email_address'] ?? ''));
        $expiresAt = isset($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : now()->addSeconds(self::DEFAULT_TTL_SECONDS);

        $role = $this->resolveRole($env, (string) ($data['role'] ?? ''));

        $invitation = OrganizationInvitation::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'email_address' => $email,
            'role_id' => $role?->id,
            'inviter_user_id' => $data['inviter_user_id'] ?? null,
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
     * Returns null when the payload is well-formed; otherwise an error spec.
     *
     * @return array{status:int,code:string,message:string}|null
     */
    private function validatePayload(Environment $env, Organization $org, array $data): ?array
    {
        $email = strtolower((string) ($data['email_address'] ?? ''));
        if ($email === '') {
            return ['status' => 422, 'code' => 'form_param_nil', 'message' => 'email_address is required.'];
        }

        $role = $this->resolveRole($env, (string) ($data['role'] ?? ''));
        if ($role === null) {
            return ['status' => 422, 'code' => 'form_param_value_invalid', 'message' => 'role is not a known role key in this environment.'];
        }

        if (! empty($data['inviter_user_id'])) {
            $exists = User::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->where('id', (string) $data['inviter_user_id'])
                ->exists();
            if (! $exists) {
                return ['status' => 422, 'code' => 'form_param_value_invalid', 'message' => 'inviter_user_id does not match a user in this environment.'];
            }
        }

        $duplicate = OrganizationInvitation::query()
            ->where('organization_id', $org->id)
            ->where('email_address', $email)
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->exists();
        if ($duplicate) {
            return ['status' => 409, 'code' => 'organization_invitation_already_exists', 'message' => 'A pending invitation for this email already exists in this organization.'];
        }

        return null;
    }

    private function findOrganization(string $id): ?Organization
    {
        $env = app(Environment::class);

        return Organization::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
    }

    private function resolveRole(Environment $env, string $key): ?Role
    {
        if ($key === '') {
            return null;
        }

        return Role::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('key', $key)
            ->first();
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
