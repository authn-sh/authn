<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Models\Session;
use App\Models\SessionActivity;
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
        return $this->transitionTo($session, Session::STATUS_ENDED);
    }

    public function remove(Session $session): Session
    {
        return $this->transitionTo($session, Session::STATUS_REMOVED);
    }

    public function replaced(Session $session): Session
    {
        return $this->transitionTo($session, Session::STATUS_REPLACED);
    }

    public function revoke(Session $session): Session
    {
        return $this->transitionTo($session, Session::STATUS_REVOKED);
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

    private function transitionTo(Session $session, string $target): Session
    {
        if ($session->status === $target) {
            return $session;
        }

        $session->status = $target;
        $session->save();

        return $session;
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
