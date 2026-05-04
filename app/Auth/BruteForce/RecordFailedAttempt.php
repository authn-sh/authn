<?php

declare(strict_types=1);

namespace App\Auth\BruteForce;

use App\Auth\TestMode\Detector;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Per-(env, identifier) failed-attempt counter (PLAN §13.4). Increments on
 * every wrong password / wrong code; locks the user when the env's
 * threshold is reached.
 *
 * Test-mode bypass: when the identifier is reserved (Detector::is*), the
 * counter is not touched — CI deliberately exercises failure paths.
 *
 * Storage is the cache (Redis in prod, array in tests). The counter
 * decays after `lockout_duration_seconds` so a stale lockout from
 * yesterday doesn't surprise an honest user today.
 */
final class RecordFailedAttempt
{
    public const DEFAULT_MAX_ATTEMPTS = 100;

    public const DEFAULT_LOCKOUT_SECONDS = 3600;

    public function record(Environment $environment, string $identifier, ?User $user = null): void
    {
        if (Detector::isTestIdentifier($identifier)) {
            return;
        }

        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];
        $attack = is_array($userSettings['attack_protection'] ?? null) ? $userSettings['attack_protection'] : [];
        $brute = is_array($attack['brute_force'] ?? null) ? $attack['brute_force'] : [];
        if (($brute['enabled'] ?? true) === false) {
            return;
        }
        $max = (int) ($brute['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS);
        $window = (int) ($brute['lockout_duration_seconds'] ?? self::DEFAULT_LOCKOUT_SECONDS);

        $cacheKey = sprintf('brute:%s:%s', $environment->id, sha1(strtolower($identifier)));
        $count = Cache::increment($cacheKey);
        if ($count === 1) {
            Cache::put($cacheKey, 1, $window);
        }
        if ($count >= $max && $user !== null) {
            $user->forceFill([
                'locked' => true,
                'lockout_expires_at' => now()->addSeconds($window),
            ])->save();
        }
    }

    public function reset(Environment $environment, string $identifier): void
    {
        Cache::forget(sprintf('brute:%s:%s', $environment->id, sha1(strtolower($identifier))));
    }
}
