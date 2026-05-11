<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Models\EmailAddress;
use App\Models\EnterpriseConnection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ScimToken;
use App\Models\User;
use App\Scim\ScimFilter;
use App\Scim\ScimFilterParser;
use App\Scim\ScimUserMapper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SCIM 2.0 Users surface (RFC 7644). Bearer-authenticated via
 * `ScimAuth`; the bound `ScimToken` scopes the rows to its
 * `organization_id` when set, falls through to env-wide otherwise.
 */
final class UsersController
{
    public function __construct(
        private readonly ScimFilterParser $filterParser,
        private readonly ScimUserMapper $mapper,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $token = app(ScimToken::class);
        $organization = $token->organization_id !== null
            ? Organization::query()->withoutGlobalScopes()->where('id', $token->organization_id)->first()
            : null;

        $filter = $this->filterParser->parse($request->query('filter'));
        $startIndex = max(1, (int) $request->query('startIndex', '1'));
        $count = min(200, max(0, (int) $request->query('count', '20')));
        $attributes = $this->parseAttributesParam($request->query('attributes'));

        $query = $this->scopedUserQuery($token->environment_id, $organization);
        if ($filter !== null) {
            $this->applyFilter($query, $filter);
        }
        $total = (clone $query)->count();
        $rows = $query
            ->orderBy('id')
            ->offset($startIndex - 1)
            ->limit($count)
            ->get();

        $resources = $rows->map(fn (User $user) => $this->mapper->toResource($user, $attributes))->all();

        return $this->scimResponse([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => count($resources),
            'Resources' => $resources,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $token = app(ScimToken::class);
        $organization = $token->organization_id !== null
            ? Organization::query()->withoutGlobalScopes()->where('id', $token->organization_id)->first()
            : null;

        $payload = $request->all();
        if (! is_array($payload) || ($payload['userName'] ?? null) === null) {
            return $this->scimError(400, 'invalidValue', 'SCIM payload missing required userName.');
        }

        $mapped = $this->mapper->fromResource($payload, $organization);
        $primaryEmail = $mapped['primary_email'];
        if ($primaryEmail === null) {
            return $this->scimError(400, 'invalidValue', 'SCIM payload must carry a primary email or RFC-822 userName.');
        }

        $existing = $this->userByEmail($token->environment_id, $primaryEmail);
        if ($existing !== null) {
            return $this->scimError(409, 'uniqueness', "A User with email {$primaryEmail} already exists.");
        }

        return DB::transaction(function () use ($token, $mapped, $primaryEmail, $organization): JsonResponse {
            /** @var array<string, mixed> $attrs */
            $attrs = $mapped['user'];
            $attrs['environment_id'] = $token->environment_id;
            $user = User::query()->withoutGlobalScopes()->create($attrs);
            $email = EmailAddress::query()->withoutGlobalScopes()->create([
                'environment_id' => $token->environment_id,
                'user_id' => $user->id,
                'email_address' => $primaryEmail,
                'verified_at' => now(),
                'is_primary' => true,
            ]);
            $user->forceFill(['primary_email_address_id' => $email->id])->save();

            if ($organization !== null) {
                // SCIM-provisioned users implicitly join the token's owning
                // organization. Role defaults to the org's `default_role` if
                // present on the connection — otherwise the operator has to
                // backfill via the FAPI memberships surface.
                $defaultRoleId = null;
                if ($token->enterprise_connection_id !== null) {
                    $conn = EnterpriseConnection::query()
                        ->withoutGlobalScopes()
                        ->where('id', $token->enterprise_connection_id)
                        ->first();
                    $defaultRoleKey = $conn?->default_role;
                    if (is_string($defaultRoleKey) && $defaultRoleKey !== '') {
                        $defaultRoleId = Role::query()
                            ->withoutGlobalScopes()
                            ->where('environment_id', $token->environment_id)
                            ->where('key', $defaultRoleKey)
                            ->value('id');
                    }
                }
                if ($defaultRoleId !== null) {
                    OrganizationMembership::query()->withoutGlobalScopes()->create([
                        'environment_id' => $token->environment_id,
                        'organization_id' => $organization->id,
                        'user_id' => $user->id,
                        'role_id' => $defaultRoleId,
                    ]);
                }
            }

            return $this->scimResponse($this->mapper->toResource($user->fresh()), 201);
        });
    }

    public function show(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $token = app(ScimToken::class);
        $user = $this->scopedUserQuery($token->environment_id, $this->organizationOf($token))->where('users.id', $id)->first();
        if ($user === null) {
            return $this->scimError(404, 'notFound', "User {$id} not found.");
        }

        return $this->scimResponse($this->mapper->toResource($user, $this->parseAttributesParam($request->query('attributes'))));
    }

    public function destroy(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $token = app(ScimToken::class);
        $user = $this->scopedUserQuery($token->environment_id, $this->organizationOf($token))->where('users.id', $id)->first();
        if ($user === null) {
            return $this->scimError(404, 'notFound', "User {$id} not found.");
        }

        $user->delete();

        return response()->json(null, 204, ['Content-Type' => 'application/scim+json']);
    }

    /**
     * @return Builder<User>
     */
    private function scopedUserQuery(string $environmentId, ?Organization $organization): Builder
    {
        $query = User::query()->withoutGlobalScopes()->where('users.environment_id', $environmentId);

        if ($organization !== null) {
            $query->whereIn('users.id', function ($sub) use ($organization): void {
                $sub->select('user_id')
                    ->from('organization_memberships')
                    ->where('organization_id', $organization->id);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyFilter(Builder $query, ScimFilter $filter): void
    {
        $value = $filter->value;
        match ($filter->attribute) {
            'userName', 'emails.value' => $query->whereExists(function ($sub) use ($filter, $value): void {
                $sub->select(DB::raw(1))
                    ->from('email_addresses')
                    ->whereColumn('email_addresses.user_id', 'users.id');
                $this->applyStringOperator($sub, 'email_addresses.email_address', $filter->operator, is_string($value) ? strtolower($value) : '');
            }),
            'externalId' => $this->applyStringOperator($query, 'users.external_id', $filter->operator, is_string($value) ? $value : ''),
            'displayName' => $this->applyStringOperator($query, 'users.username', $filter->operator, is_string($value) ? $value : ''),
            'active' => $query->where('users.banned', $value === true ? false : true),
            default => $query->whereRaw('1=0'), // unsupported attribute → empty result
        };
    }

    private function applyStringOperator(mixed $query, string $column, string $operator, string $value): void
    {
        match ($operator) {
            'eq' => $query->where($column, $value),
            'co' => $query->where($column, 'like', '%'.$this->escapeLike($value).'%'),
            'sw' => $query->where($column, 'like', $this->escapeLike($value).'%'),
            default => $query->whereRaw('1=0'),
        };
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function userByEmail(string $environmentId, string $email): ?User
    {
        $row = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $environmentId)
            ->where('email_address', strtolower($email))
            ->first();
        if ($row === null) {
            return null;
        }

        return User::query()->withoutGlobalScopes()->where('id', $row->user_id)->first();
    }

    private function organizationOf(ScimToken $token): ?Organization
    {
        if ($token->organization_id === null) {
            return null;
        }

        return Organization::query()->withoutGlobalScopes()->where('id', $token->organization_id)->first();
    }

    /**
     * @return ?list<string>
     */
    private function parseAttributesParam(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return $parts === [] ? null : $parts;
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
