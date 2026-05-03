<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Environment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-bucket rate limiting for BAPI routes (PLAN §7.3).
 *
 * Use as a route-level middleware passing the bucket name + spec:
 *
 *   Route::post(...)->middleware(RateLimit::class.':users.create,30,60');
 *
 * The first parameter names the bucket; the second is the max attempts;
 * the third is the window in seconds. Bucket key is hashed against the
 * resolved API key so that one operator's burst doesn't spend another's
 * quota. Adds `X-RateLimit-Limit / -Remaining / -Reset` to every response.
 *
 * On exhaustion: 429 with `errors[0].code = rate_limit_exceeded` and a
 * `Retry-After` header carrying the seconds until the bucket replenishes.
 */
final class RateLimit
{
    public function handle(Request $request, Closure $next, string $bucket = 'default', string $max = '60', string $windowSeconds = '60'): Response
    {
        $maxAttempts = max(1, (int) $max);
        $window = max(1, (int) $windowSeconds);

        $key = $this->resolveKey($request, $bucket);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return $this->limited($maxAttempts, $retryAfter);
        }

        RateLimiter::hit($key, $window);

        $response = $next($request);

        $remaining = RateLimiter::retriesLeft($key, $maxAttempts);
        $resetSeconds = RateLimiter::availableIn($key);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));
        $response->headers->set('X-RateLimit-Reset', (string) (time() + max(0, $resetSeconds)));

        return $response;
    }

    private function resolveKey(Request $request, string $bucket): string
    {
        $apiKey = app()->bound(ApiKey::class) ? app(ApiKey::class) : null;
        $env = app()->bound(Environment::class) ? app(Environment::class) : null;

        $scope = $apiKey?->id
            ?? $env?->id
            ?? $request->ip()
            ?? 'anonymous';

        return 'bapi:'.$bucket.':'.$scope;
    }

    private function limited(int $max, int $retryAfter): Response
    {
        return response()->json([
            'errors' => [[
                'code' => 'rate_limit_exceeded',
                'message' => "Too many requests. Retry in {$retryAfter}s.",
                'long_message' => "Too many requests. Retry in {$retryAfter}s.",
                'meta' => [
                    'limit' => $max,
                    'retry_after_seconds' => $retryAfter,
                ],
            ]],
            'trace_id' => null,
        ], 429, [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $max,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() + $retryAfter),
        ]);
    }
}
