<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ScimToken;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-auth middleware for the SCIM 2.0 surface. Verifies the
 * `Authorization: Bearer scim_<...>` header against the env-bound
 * `ScimToken` table; stamps `last_used_at` on success and binds the
 * token + parent Organization/EnterpriseConnection into the container
 * so downstream controllers can scope by them.
 *
 * Failure modes (per RFC 7644 §3.12, SCIM-format error response):
 *  - 401 when the header is missing / malformed / unknown / revoked / expired.
 */
final class ScimAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get('authorization');
        if (! is_string($header) || ! str_starts_with(strtolower($header), 'bearer ')) {
            return $this->unauthorized();
        }
        $plaintext = trim(substr($header, 7));
        if ($plaintext === '') {
            return $this->unauthorized();
        }

        $token = ScimToken::verify($plaintext);
        if ($token === null) {
            return $this->unauthorized();
        }

        $token->last_used_at = now();
        $token->save();

        Container::getInstance()->instance(ScimToken::class, $token);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'status' => '401',
            'detail' => 'SCIM bearer token is missing, invalid, revoked, or expired.',
        ], 401, ['Content-Type' => 'application/scim+json']);
    }
}
