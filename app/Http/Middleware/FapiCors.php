<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Support\AllowedOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cross-Origin Resource Sharing for FAPI.
 *
 * Echoes the request `Origin` only when it appears in the env's
 * `allowed_origins` list. OPTIONS preflights short-circuit return 204
 * with the headers attached so the browser will let the actual request
 * proceed. Requests with no Origin (server-to-server callers, native
 * apps) pass through with no CORS response headers — they don't need
 * them.
 */
final class FapiCors
{
    private const ALLOW_HEADERS = 'Content-Type, Authorization, X-Requested-With, Authn-*';

    private const ALLOW_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    private const MAX_AGE = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        $allowed = $env !== null && is_string($origin) && $origin !== ''
            && AllowedOrigins::matches($env, $origin);

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = response('', 204);
            if ($allowed) {
                $this->attachCors($response, $origin);
            }

            return $response;
        }

        $response = $next($request);
        if ($allowed) {
            $this->attachCors($response, $origin);
            $response->headers->set('Vary', trim(($response->headers->get('Vary') ?? '').', Origin', ', '));
        }

        return $response;
    }

    private function attachCors(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
        $response->headers->set('Access-Control-Max-Age', (string) self::MAX_AGE);
    }
}
