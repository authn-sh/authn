<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\Jwt\JwtTemplateNotFound;
use App\Models\Client;
use App\Models\Session;
use App\Models\User;
use App\Services\Sessions\SessionLifecycle;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `POST /v1/client/sessions/{sid}/tokens[/{template}]`
 *
 * Mints a fresh `__session` JWT for an active or pending session.
 * Refused for sessions that don't belong to the resolved Client, or
 * that have left the live status set.
 *
 * v0.1: only the implicit `default` template is honoured; any other
 * template name returns `template_not_found`. JWT templates land in v0.7.
 */
final class SessionTokenController
{
    /** Per-session burst (PLAN §7.3): 3 requests over 30 seconds. */
    public const RATE_LIMIT_MAX = 3;

    public const RATE_LIMIT_DECAY_SECONDS = 30;

    public function __construct(
        private readonly SessionTokenIssuer $issuer,
        private readonly SessionLifecycle $lifecycle,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $template = $request->route('template');
        $template = is_string($template) ? $template : null;

        $client = app(Client::class);

        $session = Session::query()
            ->where('id', $sid)
            ->where('client_id', $client->id)
            ->first();

        if ($session === null) {
            return $this->error(404, 'session_not_found', 'No session matches that id on this device.');
        }

        if (! in_array($session->status, Session::LIVE_STATUSES, true)) {
            return $this->error(401, 'session_revoked', "Session is in status {$session->status}.");
        }

        // Refuse — and revoke — when the user is banned/locked. The SDK will
        // then sign out cleanly on the next /v1/client read.
        $user = User::query()->withoutGlobalScopes()->where('id', $session->user_id)->first();
        if ($user !== null) {
            if ($user->banned) {
                $this->lifecycle->revoke($session);

                return $this->error(401, 'user_banned', 'This user is banned.');
            }
            if ($user->locked && $user->lockout_expires_at?->isFuture()) {
                $this->lifecycle->revoke($session);

                return $this->error(401, 'user_locked', 'This user is temporarily locked.');
            }
        }

        $rateLimitKey = 'fapi:session_token:'.$session->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT_MAX)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->error(
                429,
                'rate_limit_exceeded',
                "Too many token-mint requests for this session. Retry in {$retryAfter}s.",
                ['Retry-After' => (string) $retryAfter],
            );
        }
        RateLimiter::hit($rateLimitKey, self::RATE_LIMIT_DECAY_SECONDS);

        try {
            $minted = $this->issuer->mint($session, $template, $request);
        } catch (JwtTemplateNotFound $e) {
            return $this->error(404, 'template_not_found', "JWT template `{$e->templateName}` is not configured for this environment.");
        }

        if ($user !== null) {
            $this->bumpUserActivity($user);
        }

        return response()->json([
            'jwt' => $minted['jwt'],
            'expires_at' => $minted['expires_at'],
            'kid' => $minted['kid'],
        ]);
    }

    private function bumpUserActivity(User $user): void
    {
        $cacheKey = 'user:active:'.$user->id;
        if (! Cache::add($cacheKey, 1, 60)) {
            return;
        }
        $user->forceFill(['last_active_at' => now()])->saveQuietly();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ], $status, $headers);
    }
}
