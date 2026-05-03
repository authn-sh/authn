<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Session;
use App\Services\Sessions\SessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lifecycle endpoints for the live `Session` rows on a Client:
 *
 *   GET    /v1/client/sessions/{sid}            — fetch the resource
 *   POST   /v1/client/sessions/{sid}/touch      — heartbeat (debounced 60s)
 *   POST   /v1/client/sessions/{sid}/end        — user signed out on this device
 *   POST   /v1/client/sessions/{sid}/remove     — user removed this session from another device
 *
 * `tokens` lives on a separate controller so the rate limit middleware can
 * be scoped just to the mint endpoint without touching these verbs.
 */
final class SessionsController
{
    public function __construct(private readonly SessionLifecycle $lifecycle) {}

    public function show(Request $request): JsonResponse
    {
        $session = $this->loadSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        return $this->envelope($session);
    }

    public function touch(Request $request): JsonResponse
    {
        $session = $this->loadSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $session->isLive()) {
            return $this->error(409, 'session_not_live', "Session is in status {$session->status}.");
        }

        // Forward-compat capture: orgs land in v0.2 and `last_active_organization_id`
        // is already on the Session row. Storing the value now lets the SDK keep
        // sending it without a flag flip later.
        $org = $request->input('active_organization_id');
        if (is_string($org) && $org !== '' && $session->last_active_organization_id !== $org) {
            $session->forceFill(['last_active_organization_id' => $org])->saveQuietly();
        }

        $this->lifecycle->touch($session->fresh(), $request);

        return $this->envelope($session->fresh());
    }

    public function end(Request $request): JsonResponse
    {
        $session = $this->loadSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if ($session->isLive()) {
            $this->lifecycle->end($session);
            $this->clearLastActiveIfMatches($session);
        }

        return $this->envelope($session->fresh());
    }

    public function remove(Request $request): JsonResponse
    {
        $session = $this->loadSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if ($session->isLive()) {
            $this->lifecycle->remove($session);
            $this->clearLastActiveIfMatches($session);
        }

        return $this->envelope($session->fresh());
    }

    /* -------------------- helpers -------------------- */

    private function loadSession(Request $request): Session|JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $session = Session::query()
            ->withoutGlobalScopes()
            ->where('id', $sid)
            ->where('client_id', $client->id)
            ->first();
        if ($session === null) {
            return $this->error(404, 'session_not_found', 'No session matches that id on this device.');
        }

        return $session;
    }

    private function clearLastActiveIfMatches(Session $session): void
    {
        Client::query()
            ->withoutGlobalScopes()
            ->where('id', $session->client_id)
            ->where('last_active_session_id', $session->id)
            ->update(['last_active_session_id' => null]);
    }

    private function envelope(Session $session): JsonResponse
    {
        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('id', $session->client_id)
            ->first();

        return response()->json([
            'response' => $this->sessionShape($session),
            'client' => ClientResource::from($client),
        ])->header('Cache-Control', 'no-store');
    }

    private function sessionShape(Session $session): array
    {
        return [
            'object' => 'session',
            'id' => $session->id,
            'status' => $session->status,
            'last_active_at' => $session->last_active_at?->getTimestampMs(),
            'expire_at' => $session->expire_at->getTimestampMs(),
            'abandon_at' => $session->abandon_at?->getTimestampMs(),
            'last_active_organization_id' => $session->last_active_organization_id,
            'actor' => $session->actor,
            'user_id' => $session->user_id,
            'client_id' => $session->client_id,
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
