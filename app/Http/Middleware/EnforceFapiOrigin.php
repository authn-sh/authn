<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Support\AllowedOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Origin-header enforcement for state-changing FAPI requests.
 *
 * GET / HEAD / OPTIONS skip — read-only methods don't need CSRF
 * mitigation, and OPTIONS is the preflight FapiCors short-circuits.
 *
 * Bearer-token requests (mobile / native paths) skip too — they're
 * authenticated by the token, not a cookie, so a forged Origin can't
 * ride a logged-in session.
 *
 * Otherwise the Origin (or Referer host as a fallback) MUST appear in
 * `Environment.allowed_origins`. Mismatched / missing → 403
 * `origin_invalid` with the offending origin and the configured allow
 * list in `meta` so an operator can copy-paste the value into the
 * dashboard.
 *
 * Public bootstrap endpoints (`/v1/environment`, `/v1/client`,
 * `/.well-known/*`) opt out via route-level `withoutMiddleware`.
 */
final class EnforceFapiOrigin
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if ($this->hasBearerToken($request)) {
            return $next($request);
        }

        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        if ($env === null) {
            return $this->forbid('environment_not_resolved', 'No environment is bound for this request.');
        }

        $origin = $request->headers->get('Origin');
        if (! is_string($origin) || $origin === '') {
            $referer = $request->headers->get('Referer');
            if (is_string($referer) && $referer !== '') {
                $parts = parse_url($referer);
                if (! empty($parts['scheme']) && ! empty($parts['host'])) {
                    $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
                }
            }
        }

        if (! is_string($origin) || $origin === '') {
            return $this->forbid('origin_invalid', 'Request must include an Origin header.', null, $env);
        }

        if (! AllowedOrigins::matches($env, $origin)) {
            return $this->forbid('origin_invalid', 'Request origin is not in the allowed origins list.', $origin, $env);
        }

        return $next($request);
    }

    private function hasBearerToken(Request $request): bool
    {
        $header = $request->headers->get('Authorization');
        if (! is_string($header)) {
            return false;
        }

        return (bool) preg_match('/^Bearer\s+\S+$/i', $header);
    }

    private function forbid(string $code, string $message, ?string $origin = null, ?Environment $env = null): Response
    {
        $meta = [];
        if ($origin !== null) {
            $meta['origin'] = $origin;
        }
        if ($env !== null) {
            $meta['allowed'] = is_array($env->allowed_origins) ? $env->allowed_origins : [];
        }

        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message.' Add this origin in your dashboard under Configure → Domains → Allowed origins, or use a server-to-server credential.',
                'meta' => $meta,
            ]],
            'trace_id' => null,
        ], 403);
    }
}
