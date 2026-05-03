<?php

declare(strict_types=1);

namespace App\Http\Controllers\WellKnown;

use App\Models\Environment;
use App\Models\SigningKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /.well-known/jwks.json` — published per environment on the FAPI
 * host. Returns every SigningKey whose status is in the published set
 * (active, retiring, pending) so a token signed by a recently-rotated
 * key still verifies during the grace window.
 *
 * Cache header set to `public, max-age=300, must-revalidate` per PLAN
 * §10.4.
 */
final class JwksController
{
    public function __invoke(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        $keys = $env->signingKeys()
            ->whereIn('status', [
                SigningKey::STATUS_ACTIVE,
                SigningKey::STATUS_RETIRING,
                SigningKey::STATUS_PENDING,
            ])
            ->orderByDesc('activated_at')
            ->get()
            ->map(fn (SigningKey $key) => $key->public_jwk)
            ->values()
            ->all();

        return response()->json(['keys' => $keys])
            ->header('Cache-Control', 'public, max-age=300, must-revalidate')
            ->header('Content-Type', 'application/json');
    }
}
