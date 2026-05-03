<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Models\Session;
use App\Models\User;
use App\Services\Sessions\SessionLifecycle;
use App\Services\Sessions\SessionTokenVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a `__session` JWT and binds the resolved Session + User into the
 * container for `/v1/me/*` controllers.
 *
 * Token source order:
 *   1. `Authorization: Bearer <jwt>` — preferred for cross-origin and native
 *      callers; can't be carried by a SameSite=Lax cookie there.
 *   2. `__session` cookie — same-origin browser path.
 *
 * Verification is local: the JWT signature, `iss`, and validity window are
 * checked against the env's published key set without a DB lookup. After the
 * signature check the Session row is loaded once (cached for 30s by sid) so
 * status revocations propagate fast even with cached tokens.
 */
final class AuthenticateSessionToken
{
    public const ERR_TOKEN_MISSING = 'session_token_missing';

    public const ERR_TOKEN_INVALID = 'session_token_invalid';

    public const ERR_SESSION_REVOKED = 'session_revoked';

    public const ERR_USER_BANNED = 'user_banned';

    public const ERR_USER_LOCKED = 'user_locked';

    public function __construct(
        private readonly SessionTokenVerifier $verifier,
        private readonly SessionLifecycle $lifecycle,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        if (! $env instanceof Environment) {
            return $this->error(401, self::ERR_TOKEN_INVALID, 'No environment is bound for this request.');
        }

        $jwt = $this->extractJwt($request);
        if ($jwt === null) {
            return $this->error(401, self::ERR_TOKEN_MISSING, 'Authorization header or __session cookie is required.');
        }

        $claims = $this->verifier->verify($jwt, $env);
        if ($claims === null) {
            return $this->error(401, self::ERR_TOKEN_INVALID, 'Session token is invalid or expired.');
        }

        $sid = $claims['sid'] ?? null;
        $sub = $claims['sub'] ?? null;
        if (! is_string($sid) || ! is_string($sub) || $sid === '' || $sub === '') {
            return $this->error(401, self::ERR_TOKEN_INVALID, 'Token is missing required claims.');
        }

        $session = Session::query()->withoutGlobalScopes()->where('id', $sid)->first();
        if ($session === null || ! in_array($session->status, Session::LIVE_STATUSES, true)) {
            return $this->error(401, self::ERR_SESSION_REVOKED, 'Session is no longer live.');
        }

        $user = User::query()->withoutGlobalScopes()->where('id', $sub)->first();
        if ($user === null) {
            return $this->error(401, self::ERR_TOKEN_INVALID, 'User on token does not exist.');
        }

        if ($user->banned) {
            $this->lifecycle->revoke($session);

            return $this->error(401, self::ERR_USER_BANNED, 'This user is banned.');
        }
        if ($user->locked && $user->lockout_expires_at?->isFuture()) {
            $this->lifecycle->revoke($session);

            return $this->error(401, self::ERR_USER_LOCKED, 'This user is temporarily locked.');
        }

        app()->instance(Session::class, $session);
        app()->instance(User::class, $user);

        return $next($request);
    }

    private function extractJwt(Request $request): ?string
    {
        $auth = $request->headers->get('Authorization');
        if (is_string($auth) && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) === 1) {
            return $m[1];
        }
        $cookie = $request->cookie('__session');
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        return null;
    }

    private function error(int $status, string $code, string $message): Response
    {
        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ], $status);
    }
}
