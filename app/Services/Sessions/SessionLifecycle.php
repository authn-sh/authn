<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Models\Client;
use App\Models\Environment;
use App\Models\OrganizationMembership;
use App\Models\Session;
use App\Models\SessionActivity;
use App\Webhooks\Emitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Status-flip + heartbeat orchestration for Session rows.
 *
 *   touch     — refresh `last_active_at` and append a SessionActivity row.
 *               Debounced to once per minute per session via the cache.
 *   end       — user-initiated sign-out on this device (status → ended).
 *   remove    — user removed this device's access from another device
 *               (status → removed).
 *   replaced  — server evicted this session because a newer one took
 *               its slot (multi_session=false, or LRU eviction).
 *   revoked   — operator-initiated revoke (BAPI), or refused for
 *               banned/locked users.
 *   expire    — TTL elapsed; called by AU-19's reaper.
 *
 * Each method returns the refreshed Session (or, in touch's debounced
 * case, the same instance with its `last_active_at` already advanced).
 */
final class SessionLifecycle
{
    /** Per-session debounce window for touch writes (seconds). Matches PLAN §10. */
    public const TOUCH_DEBOUNCE_SECONDS = 60;

    /**
     * Default cap when `multi_session = true`. Configurable per-env via
     * `Environment.user_settings.sessions.max_concurrent_sessions_per_client`
     * once the dashboard exposes it (AU-13). Matches PLAN §13.5.
     */
    public const DEFAULT_MAX_CONCURRENT_SESSIONS = 10;

    private const TOUCH_CACHE_PREFIX = 'session:touch:';

    public function touch(Session $session, ?Request $request = null): Session
    {
        $cacheKey = self::TOUCH_CACHE_PREFIX.$session->id;

        if (! Cache::add($cacheKey, 1, self::TOUCH_DEBOUNCE_SECONDS)) {
            return $session;
        }

        $now = now();
        $session->forceFill(['last_active_at' => $now])->saveQuietly();

        SessionActivity::create([
            'session_id' => $session->id,
            'device_type' => $request?->header('Sec-CH-UA-Mobile') ? 'mobile' : 'browser',
            'is_mobile' => $request !== null
                ? str_contains((string) $request->header('User-Agent'), 'Mobile')
                : null,
            'browser_name' => $request !== null ? $this->extractBrowser($request) : null,
            'browser_version' => null,
            'os_name' => null,
            'ip_address' => $request?->ip(),
            'city' => null,
            'country' => null,
        ]);

        return $session;
    }

    public function end(Session $session): Session
    {
        $session = $this->transitionTo($session, Session::STATUS_ENDED);
        $this->emit('session.ended', $session);

        return $session;
    }

    public function remove(Session $session): Session
    {
        $session = $this->transitionTo($session, Session::STATUS_REMOVED);
        $this->emit('session.removed', $session);

        return $session;
    }

    public function replaced(Session $session): Session
    {
        $session = $this->transitionTo($session, Session::STATUS_REPLACED);
        $this->emit('session.replaced', $session);

        return $session;
    }

    public function revoke(Session $session): Session
    {
        $session = $this->transitionTo($session, Session::STATUS_REVOKED);
        $this->emit('session.revoked', $session);

        return $session;
    }

    /**
     * Fire a `session.created` event. Called from sign-in / sign-up
     * controllers right after Session::create().
     */
    public function notifyCreated(Session $session): void
    {
        $this->emit('session.created', $session);
    }

    public function expire(Session $session): Session
    {
        return $this->transitionTo($session, Session::STATUS_EXPIRED);
    }

    public function activate(Session $session): Session
    {
        if ($session->status === Session::STATUS_ACTIVE) {
            return $session;
        }

        return $this->transitionTo($session, Session::STATUS_ACTIVE);
    }

    /**
     * Apply the env's multi-session policy after a fresh Session was minted.
     *
     * - multi_session = false: every other live session on the Client is
     *   evicted (status → replaced).
     * - multi_session = true:  if the live count exceeds the configured cap,
     *   evict the oldest-by-`last_active_at` until back under the cap.
     *
     * Returns the list of evicted session ids so the caller can fire
     * webhooks (AU-15 will subscribe to this list).
     *
     * @return list<string>
     */
    public function enforceMultiSessionPolicy(Environment $environment, Client $client, Session $newSession): array
    {
        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];
        $sessionCfg = is_array($userSettings['sessions'] ?? null) ? $userSettings['sessions'] : [];
        $multiSession = (bool) ($sessionCfg['multi_session'] ?? true);
        $maxConcurrent = (int) ($sessionCfg['max_concurrent_sessions_per_client'] ?? self::DEFAULT_MAX_CONCURRENT_SESSIONS);

        $live = Session::query()
            ->withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->where('id', '!=', $newSession->id)
            ->whereIn('status', Session::LIVE_STATUSES)
            ->orderBy('last_active_at')
            ->get();

        $evicted = [];

        if (! $multiSession) {
            foreach ($live as $session) {
                $this->replaced($session);
                $evicted[] = $session->id;
            }

            return $evicted;
        }

        $excess = ($live->count() + 1) - max(1, $maxConcurrent);
        if ($excess <= 0) {
            return [];
        }
        foreach ($live->take($excess) as $session) {
            $this->replaced($session);
            $evicted[] = $session->id;
        }

        return $evicted;
    }

    /**
     * Lazy collapse on `GET /v1/client` when multi_session was toggled to
     * false but the client still carries multiple live sessions. Keeps the
     * one with the most-recent `last_active_at`; evicts the rest.
     *
     * @return list<string>
     */
    public function enforceSingleSessionOnRead(Environment $environment, Client $client): array
    {
        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];
        $sessionCfg = is_array($userSettings['sessions'] ?? null) ? $userSettings['sessions'] : [];
        if (($sessionCfg['multi_session'] ?? true) !== false) {
            return [];
        }

        $live = Session::query()
            ->withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->whereIn('status', Session::LIVE_STATUSES)
            ->orderByDesc('last_active_at')
            ->get();

        if ($live->count() <= 1) {
            return [];
        }

        $evicted = [];
        foreach ($live->slice(1) as $session) {
            $this->replaced($session);
            $evicted[] = $session->id;
        }

        return $evicted;
    }

    private function transitionTo(Session $session, string $target): Session
    {
        if ($session->status === $target) {
            return $session;
        }

        $session->status = $target;
        $session->save();

        return $session;
    }

    private function emit(string $type, Session $session): void
    {
        $env = Environment::query()->withoutGlobalScopes()->where('id', $session->environment_id)->first();
        if ($env === null) {
            return;
        }
        app(Emitter::class)->emit($type, $this->shape($session), $env, (bool) $session->was_test);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Session $session): array
    {
        return [
            'object' => 'session',
            'id' => $session->id,
            'status' => $session->status,
            'client_id' => $session->client_id,
            'user_id' => $session->user_id,
            'last_active_at' => $session->last_active_at?->getTimestampMs(),
            'expire_at' => $session->expire_at->getTimestampMs(),
            'abandon_at' => $session->abandon_at?->getTimestampMs(),
            'last_active_organization_id' => $session->last_active_organization_id,
            'organization' => $this->activeOrganizationShape($session),
            'actor' => $session->actor,
            'created_at' => $session->created_at?->getTimestampMs(),
            'updated_at' => $session->updated_at?->getTimestampMs(),
        ];
    }

    /**
     * Compact `{ id, slug, role, permissions[] }` block for the
     * session's active organization (PLAN §4.4 / OA-5 / AU-11). Returns
     * null when the session has no active org or when the membership row
     * has been deleted between the touch and the emit.
     *
     * @return array{id: string, slug: string, role: string, permissions: list<string>}|null
     */
    private function activeOrganizationShape(Session $session): ?array
    {
        $orgId = $session->last_active_organization_id;
        if (! is_string($orgId) || $orgId === '') {
            return null;
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $session->user_id)
            ->with(['organization', 'role.permissions'])
            ->first();
        if ($membership === null || $membership->organization === null || $membership->role === null) {
            return null;
        }

        return [
            'id' => $membership->organization->id,
            'slug' => $membership->organization->slug,
            'role' => $membership->role->key,
            'permissions' => $membership->role->permissions->pluck('key')->unique()->values()->all(),
        ];
    }

    private function extractBrowser(Request $request): ?string
    {
        $ua = (string) $request->header('User-Agent');
        if ($ua === '') {
            return null;
        }
        if (str_contains($ua, 'Firefox/')) {
            return 'Firefox';
        }
        if (str_contains($ua, 'Edg/')) {
            return 'Edge';
        }
        if (str_contains($ua, 'Chrome/')) {
            return 'Chrome';
        }
        if (str_contains($ua, 'Safari/')) {
            return 'Safari';
        }

        return 'Unknown';
    }
}
