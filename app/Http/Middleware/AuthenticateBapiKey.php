<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Environment;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates BAPI requests with an `Authorization: Bearer sk_…` header.
 *
 * Skeleton in AU-3:
 *   - Resolves the ApiKey by SHA-256 hash.
 *   - 401s on missing / unknown / revoked keys.
 *   - Binds the resolved Environment + Project into the container.
 *   - Updates `last_used_at` at most once per minute per key (Redis-backed
 *     debounce).
 *
 * AU-13 layers idempotency, rate-limit accounting, and the
 * `created_by_user_id` audit trail on top.
 */
final class AuthenticateBapiKey
{
    /**
     * Cache key prefix for the per-key `last_used_at` debounce.
     */
    private const TOUCH_CACHE_PREFIX = 'apikey:touch:';

    /** Debounce window for `last_used_at` writes (seconds). */
    private const TOUCH_DEBOUNCE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractBearer($request);
        if ($token === null) {
            return $this->unauthorized('authentication_invalid', 'Missing Authorization header.');
        }

        if (! str_starts_with($token, 'sk_test_') && ! str_starts_with($token, 'sk_live_')) {
            return $this->unauthorized('authentication_invalid', 'Authorization token is not a secret key.');
        }

        $hash = hash('sha256', $token);
        $apiKey = ApiKey::query()
            ->with('environment.project')
            ->where('hashed_secret', $hash)
            ->whereNull('revoked_at')
            ->where('kind', ApiKey::KIND_SECRET)
            ->first();

        if ($apiKey === null) {
            return $this->unauthorized('authentication_invalid', 'Unknown or revoked secret key.');
        }

        $this->touch($apiKey);

        app()->instance(Environment::class, $apiKey->environment);
        app()->instance(Project::class, $apiKey->environment->project);
        app()->instance(ApiKey::class, $apiKey);

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (! is_string($header)) {
            return null;
        }

        if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Update `last_used_at` at most once per minute per key. The cached
     * sentinel acts as the debounce — if it's still present we skip the
     * UPDATE, otherwise we set it (TTL = debounce window) and write.
     */
    private function touch(ApiKey $apiKey): void
    {
        $cacheKey = self::TOUCH_CACHE_PREFIX.$apiKey->id;

        if (! Cache::add($cacheKey, 1, self::TOUCH_DEBOUNCE_SECONDS)) {
            return;
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    private function unauthorized(string $code, string $message): Response
    {
        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message.' Issue or rotate a secret key in the dashboard and pass it as `Authorization: Bearer <secret>`.',
                'meta' => [],
            ]],
            'trace_id' => null,
        ], 401);
    }
}
