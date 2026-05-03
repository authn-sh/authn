<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Http\Requests\Bapi\Invitations\BulkInvitationRequest;
use App\Http\Requests\Bapi\Invitations\CreateInvitationRequest;
use App\Jobs\Mail\SendInvitationEmail;
use App\Models\Environment;
use App\Models\Invitation;
use App\Services\Tickets\TicketIssuer;
use App\Support\Idempotency;
use App\Support\IdempotencyMismatch;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * BAPI invitations surface.
 *
 *   GET    /v1/invitations              filters: status, query
 *   POST   /v1/invitations              create + dispatch SendInvitationEmail
 *   POST   /v1/invitations/bulk         1–100, partial failures returned per row
 *   POST   /v1/invitations/{id}/revoke
 */
final class InvitationsController
{
    public const DEFAULT_TTL_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(private readonly TicketIssuer $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = Invitation::query()->withoutGlobalScopes()->where('environment_id', $env->id);
        if ($request->has('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }
        if ($request->has('query')) {
            $needle = '%'.(string) $request->input('query').'%';
            $query->where('email_address', 'like', $needle);
        }
        $query->latest('created_at');
        $limit = max(1, min(500, (int) $request->input('limit', 10)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (Invitation $i) => $this->shape($i))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(CreateInvitationRequest $request): JsonResponse
    {
        $env = app(Environment::class);
        $idempotencyKey = $request->header('Idempotency-Key');

        try {
            $payload = Idempotency::cache(
                envId: $env->id,
                key: is_string($idempotencyKey) ? $idempotencyKey : null,
                requestHash: Idempotency::hashRequest($request->method(), $request->path(), $request->all()),
                fn: fn () => $this->createOne($env, $request->all()),
            );
        } catch (IdempotencyMismatch $e) {
            return $this->error(422, Idempotency::ERROR_CODE_MISMATCH, $e->getMessage());
        }

        return response()->json($payload['body'], $payload['status']);
    }

    public function bulk(BulkInvitationRequest $request): JsonResponse
    {
        $env = app(Environment::class);
        $rows = (array) $request->input('invitations');
        $results = [];
        foreach ($rows as $i => $row) {
            try {
                $payload = $this->createOne($env, (array) $row);
                $results[] = ['index' => $i, 'success' => $payload['status'] === 201, 'response' => $payload['body']];
            } catch (Throwable $e) {
                $results[] = [
                    'index' => $i,
                    'success' => false,
                    'errors' => [['code' => 'bulk_invitation_failed', 'message' => $e->getMessage(), 'long_message' => $e->getMessage(), 'meta' => []]],
                ];
            }
        }
        $created = array_sum(array_map(fn ($r) => $r['success'] ? 1 : 0, $results));

        return response()->json([
            'object' => 'bulk_invitation_result',
            'total' => count($results),
            'created' => $created,
            'results' => $results,
        ]);
    }

    public function revoke(string $id): JsonResponse
    {
        $env = app(Environment::class);
        $invitation = Invitation::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
        if ($invitation === null) {
            return $this->error(404, 'invitation_not_found', 'No invitation matches that id.');
        }
        if ($invitation->status === Invitation::STATUS_PENDING) {
            $invitation->forceFill([
                'status' => Invitation::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();
        }

        return response()->json($this->shape($invitation->fresh()));
    }

    /* -------------------- helpers -------------------- */

    private function createOne(Environment $env, array $data): array
    {
        $email = strtolower((string) ($data['email_address'] ?? ''));
        $expiresAt = isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : now()->addSeconds(self::DEFAULT_TTL_SECONDS);

        $invitation = Invitation::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'email_address' => $email,
            'status' => Invitation::STATUS_PENDING,
            'public_metadata' => $data['public_metadata'] ?? [],
            'redirect_url' => $data['redirect_url'] ?? null,
            'expires_at' => $expiresAt,
            'template_slug' => $data['template_slug'] ?? 'invitation',
        ]);

        $jwt = $this->tickets->issueForInvitation($invitation->fresh());
        $url = $this->ticketUrl($env, $jwt, $invitation->redirect_url);

        SendInvitationEmail::dispatch(
            $env->id,
            $email,
            $url,
            is_array($invitation->public_metadata) ? $invitation->public_metadata : [],
            $invitation->template_slug,
        );

        return [
            'status' => 201,
            'body' => $this->shape($invitation->fresh(), $url),
        ];
    }

    private function ticketUrl(Environment $env, string $jwt, ?string $redirect): string
    {
        $base = Url::fapi($env, '');
        $query = http_build_query(array_filter([
            '__authn_ticket' => $jwt,
            'redirect_url' => $redirect,
        ], fn ($v) => $v !== null && $v !== ''));

        return rtrim($base, '/').'/sign-up?'.$query;
    }

    private function shape(Invitation $invitation, ?string $url = null): array
    {
        return [
            'object' => 'invitation',
            'id' => $invitation->id,
            'email_address' => $invitation->email_address,
            'redirect_url' => $invitation->redirect_url,
            'public_metadata' => is_array($invitation->public_metadata) ? $invitation->public_metadata : [],
            'status' => $invitation->status,
            'revoked' => $invitation->status === Invitation::STATUS_REVOKED,
            'url' => $url ?? $this->ticketUrl(
                app(Environment::class),
                $this->tickets->issueForInvitation($invitation),
                $invitation->redirect_url,
            ),
            'expires_at' => $invitation->expires_at?->getTimestampMs(),
            'created_at' => $invitation->created_at?->getTimestampMs(),
            'updated_at' => $invitation->updated_at?->getTimestampMs(),
        ];
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
