<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\Environment;
use App\Services\Client\ClientResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the device's Client row from the `__client` cookie (production)
 * or the `__authn_db_jwt` query parameter (cross-origin dev). Binds the
 * resolved Client into the container so downstream FAPI controllers can
 * fetch it via `app(Client::class)`.
 *
 * On a missing or invalid cookie this middleware returns 401 with
 * `client_not_found`. The controllers that don't require an existing
 * Client (e.g. `GET /v1/client` itself, which mints one on the fly)
 * skip this middleware.
 */
final class ResolveClientFromCookie
{
    public function __construct(private readonly ClientResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $environment = app()->bound(Environment::class) ? app(Environment::class) : null;
        if ($environment === null) {
            return $this->unauthorized('environment_not_resolved', 'No environment is bound for this request.');
        }

        $cookie = $request->cookie('__client') ?? $request->query('__authn_db_jwt');
        $client = $this->resolver->fromCookie(is_string($cookie) ? $cookie : null, $environment);

        if ($client === null) {
            return $this->unauthorized('client_not_found', 'No client matches the supplied __client cookie.');
        }

        app()->instance(Client::class, $client);

        return $next($request);
    }

    private function unauthorized(string $code, string $message): Response
    {
        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ], 401);
    }
}
