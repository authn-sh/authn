<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * BAPI idempotency layer (PLAN §7.5).
 *
 * Pattern:
 *
 *   $payload = Idempotency::cache(
 *       envId:        $env->id,
 *       key:          $request->header('Idempotency-Key'),
 *       requestHash:  Idempotency::hashRequest($request),
 *       fn:           fn () => doTheThing(),
 *   );
 *
 * Behaviour:
 *
 * - First call for a given (env, key): runs `$fn`, caches the JSON-encoded
 *   result + the request hash for 24 hours, returns the result.
 * - Replay with the same key + same body hash: returns the cached result
 *   without re-running `$fn`.
 * - Replay with the same key + a *different* body hash: throws
 *   IdempotencyMismatch — the controller maps to a 422 response.
 * - When `$key` is null/empty, runs `$fn` directly with no caching.
 */
final class Idempotency
{
    public const CACHE_TTL_SECONDS = 24 * 60 * 60;

    public const ERROR_CODE_MISMATCH = 'idempotency_key_in_use_with_different_request';

    private const CACHE_PREFIX = 'bapi:idem:';

    /**
     * @template TResult of array
     *
     * @param  callable(): TResult  $fn
     * @return TResult
     *
     * @throws IdempotencyMismatch when the same key was already used with a different request body.
     */
    public static function cache(string $envId, ?string $key, string $requestHash, callable $fn): array
    {
        if ($key === null || $key === '') {
            return $fn();
        }
        $cacheKey = self::CACHE_PREFIX.$envId.':'.$key;
        $existing = Cache::get($cacheKey);
        if (is_array($existing) && isset($existing['request_hash'], $existing['result'])) {
            if (! hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new IdempotencyMismatch('Idempotency-Key has already been used with a different request body.');
            }

            return $existing['result'];
        }

        $result = $fn();
        Cache::put(
            $cacheKey,
            ['request_hash' => $requestHash, 'result' => $result],
            self::CACHE_TTL_SECONDS,
        );

        return $result;
    }

    /**
     * Stable hash of the request shape that participates in idempotency. We
     * include method + path + body so a `POST /v1/users` and a
     * `POST /v1/invitations` with the same key are not treated as the same
     * call.
     */
    public static function hashRequest(string $method, string $path, array $body): string
    {
        $canonical = json_encode([
            'method' => strtoupper($method),
            'path' => $path,
            'body' => self::canonicalize($body),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $canonical);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = [];
            $keys = array_keys($value);
            sort($keys);
            foreach ($keys as $k) {
                $out[$k] = self::canonicalize($value[$k]);
            }
            if ($isList) {
                return array_values(array_map(self::canonicalize(...), $value));
            }

            return $out;
        }

        return $value;
    }
}
