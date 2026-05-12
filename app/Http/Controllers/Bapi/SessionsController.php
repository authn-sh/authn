<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Auth\Jwt\JwtTemplateNotFound;
use App\Models\Environment;
use App\Models\Session;
use App\Models\SessionActivity;
use App\Services\Sessions\SessionLifecycle;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BAPI sessions surface.
 *
 *   GET    /v1/sessions                    list with filters
 *   GET    /v1/sessions/{id}
 *   POST   /v1/sessions/{id}/revoke
 *   POST   /v1/sessions/{id}/tokens[/{template}]
 */
final class SessionsController
{
    public function __construct(
        private readonly SessionLifecycle $lifecycle,
        private readonly SessionTokenIssuer $issuer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = Session::query()->withoutGlobalScopes()->where('environment_id', $env->id);
        if ($request->has('client_id')) {
            $query->whereIn('client_id', (array) $request->input('client_id'));
        }
        if ($request->has('user_id')) {
            $query->whereIn('user_id', (array) $request->input('user_id'));
        }
        if ($request->has('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }
        $query->latest('last_active_at');
        $limit = max(1, min(500, (int) $request->input('limit', 10)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (Session $s) => $this->shape($s))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(string $id): JsonResponse
    {
        $session = $this->find($id);
        if ($session === null) {
            return $this->error(404, 'session_not_found', 'No session matches that id.');
        }

        return response()->json($this->shape($session));
    }

    public function revoke(string $id): JsonResponse
    {
        $session = $this->find($id);
        if ($session === null) {
            return $this->error(404, 'session_not_found', 'No session matches that id.');
        }
        if ($session->isLive()) {
            $this->lifecycle->revoke($session);
        }

        return response()->json($this->shape($session->fresh()));
    }

    public function tokens(Request $request, string $id): JsonResponse
    {
        $session = $this->find($id);
        if ($session === null) {
            return $this->error(404, 'session_not_found', 'No session matches that id.');
        }
        if (! $session->isLive()) {
            return $this->error(409, 'session_not_live', "Session is in status {$session->status}.");
        }
        $template = $request->route('template');

        try {
            $minted = $this->issuer->mint($session, is_string($template) ? $template : null, $request);
        } catch (JwtTemplateNotFound $e) {
            return $this->error(404, 'template_not_found', "JWT template `{$e->templateName}` is not configured for this environment.");
        }

        return response()->json([
            'object' => 'token',
            'jwt' => $minted['jwt'],
            'expires_at' => $minted['expires_at'],
            'kid' => $minted['kid'],
        ]);
    }

    private function find(string $id): ?Session
    {
        $env = app(Environment::class);

        return Session::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
    }

    private function shape(Session $session): array
    {
        $latestActivity = SessionActivity::query()
            ->where('session_id', $session->id)
            ->latest('id')
            ->first();

        return [
            'object' => 'session',
            'id' => $session->id,
            'status' => $session->status,
            'client_id' => $session->client_id,
            'user_id' => $session->user_id,
            'last_active_at' => ($session->last_active_at ?? $session->created_at ?? now())->getTimestampMs(),
            'expire_at' => $session->expire_at->getTimestampMs(),
            // Falls back to expire_at when not set — the spec marks this
            // required, and a Session without an abandon_at simply doesn't
            // need step-up reaping (only `pending` ones do).
            'abandon_at' => ($session->abandon_at ?? $session->expire_at)->getTimestampMs(),
            'last_active_organization_id' => $session->last_active_organization_id,
            'actor' => $session->actor,
            'latest_activity' => $this->activityShape($session, $latestActivity),
            'created_at' => $session->created_at?->getTimestampMs(),
            'updated_at' => $session->updated_at?->getTimestampMs(),
        ];
    }

    private function activityShape(Session $session, ?SessionActivity $activity): array
    {
        // SessionActivity is internal (auto-increment id); the public Id
        // pattern needs a 26-char Crockford ULID. Build a deterministic
        // synthetic by re-encoding the bigint via a Crockford alphabet —
        // good enough for spec parity without forking the storage layer.
        $rawId = $activity?->id ?? 0;
        $base = self::crockford((int) $rawId);
        $idPart = str_pad($base, 26, '0', STR_PAD_LEFT);

        if ($activity === null) {
            return [
                'object' => 'session_activity',
                'id' => 'sact_'.$idPart,
            ];
        }

        return [
            'object' => 'session_activity',
            'id' => 'sact_'.$idPart,
            'device_type' => $activity->device_type,
            'is_mobile' => (bool) $activity->is_mobile,
            'browser_name' => $activity->browser_name,
            'browser_version' => $activity->browser_version,
            'ip_address' => $activity->ip_address,
            'city' => $activity->city,
            'country' => $activity->country,
        ];
    }

    private static function crockford(int $n): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        if ($n === 0) {
            return '0';
        }
        $out = '';
        while ($n > 0) {
            $out = $alphabet[$n % 32].$out;
            $n = intdiv($n, 32);
        }

        return $out;
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
