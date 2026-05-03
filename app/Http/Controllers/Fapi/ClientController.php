<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Session;
use App\Services\Client\ClientResolver;
use App\Services\Sessions\HandshakeToken;
use App\Services\Sessions\SessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Five endpoints for the `Client` resource:
 *
 *   GET    /v1/client            — return the device's snapshot, minting
 *                                  a fresh row when no cookie matches
 *                                  (idempotent first-touch)
 *   PUT    /v1/client            — explicit fresh-client mint (used by
 *                                  the SDK on first boot when it knows
 *                                  there is no prior cookie)
 *   DELETE /v1/client            — sign out everything on this device
 *                                  and clear the cookie
 *   GET    /v1/client/handshake  — exchange an SSR handshake token for
 *                                  a __client cookie
 *
 * The `__session` cookie is the SDK's responsibility (it reads it to know
 * when to refresh); we only set / clear `__client` here.
 */
final class ClientController
{
    public function __construct(
        private readonly ClientResolver $resolver,
        private readonly SessionLifecycle $sessions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $cookie = $request->cookie('__client');
        $client = $this->resolver->fromCookie(is_string($cookie) ? $cookie : null, $env);

        $createdHere = false;
        if ($client === null) {
            $client = Client::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'last_active_at' => now(),
            ]);
            $createdHere = true;
        } else {
            // Lazy collapse if the env was toggled to single-session mode.
            $this->sessions->enforceSingleSessionOnRead($env, $client);
        }

        $response = response()
            ->json(ClientResource::from($client))
            ->header('Cache-Control', 'no-store');

        if ($createdHere || $cookie === null) {
            $response = $response->withCookie($this->buildCookie($client));
        }

        return $response;
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        $client = Client::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'last_active_at' => now(),
        ]);

        return response()
            ->json(ClientResource::from($client))
            ->header('Cache-Control', 'no-store')
            ->withCookie($this->buildCookie($client));
    }

    public function destroy(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $cookie = $request->cookie('__client');
        $client = $this->resolver->fromCookie(is_string($cookie) ? $cookie : null, $env);

        if ($client !== null) {
            $client->sessions()
                ->whereIn('status', Session::LIVE_STATUSES)
                ->each(fn (Session $session) => $this->sessions->end($session));
        }

        return response()
            ->json([
                'deleted' => true,
                'client' => null,
            ])
            ->header('Cache-Control', 'no-store')
            ->withCookie(Cookie::forget('__client'));
    }

    public function handshake(Request $request, HandshakeToken $tokens): JsonResponse
    {
        $env = app(Environment::class);
        $token = $request->input('handshake_token') ?? $request->query('handshake_token');

        if (! is_string($token) || $token === '') {
            return $this->error(400, 'handshake_token_missing', 'A handshake_token is required.');
        }

        $client = $tokens->verify($token, $env);
        if ($client === null) {
            return $this->error(401, 'handshake_token_invalid', 'Handshake token is invalid, expired, or already redeemed.');
        }

        return response()
            ->json(ClientResource::from($client))
            ->header('Cache-Control', 'no-store')
            ->withCookie($this->buildCookie($client));
    }

    /**
     * Build the `__client` cookie. HttpOnly, SameSite=Lax, Secure when
     * the request itself is over HTTPS — the latter mirrors PLAN §6.3.
     * Lifetime: 1 year sliding (Laravel's Cookie::make takes minutes).
     */
    private function buildCookie(Client $client): \Symfony\Component\HttpFoundation\Cookie
    {
        $value = $this->resolver->mintCookieValue($client);

        return Cookie::make(
            name: '__client',
            value: $value,
            minutes: 60 * 24 * 365,
            path: '/',
            domain: null,
            secure: request()->secure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    private function error(int $status, string $code, string $message): JsonResponse
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
