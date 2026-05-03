<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Http\Resources\EnvironmentResource;
use App\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * `GET /v1/environment` — public, no auth.
 *
 * Cached at the application layer keyed by `(env_id,
 * environment.updated_at)` so config-page edits invalidate
 * automatically; falls through after a 60-second safety TTL anyway.
 *
 * Response carries `Cache-Control: public, max-age=60` so CDNs can
 * also do their share — no Set-Cookie ever set on this endpoint.
 */
final class EnvironmentController
{
    public function show(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        $cacheKey = sprintf('fapi:environment:%s:%s', $env->id, $env->updated_at?->getTimestamp() ?? 0);
        $payload = Cache::remember($cacheKey, 60, fn () => EnvironmentResource::from($env));

        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=60');
    }
}
