<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Models\Client;
use App\Models\Environment;
use App\Models\SignInAttempt;
use App\Models\SignUpAttempt;
use App\Services\MagicLink\MagicLinkVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Click-time handler for the email_link strategy.
 *
 *   GET /v1/client/magic-link/redeem?__authn_magic_link=<jwt>&redirect_url=<url>
 *
 * Verifies the JWT against the env's signing keys, marks the matching
 * VerificationCode consumed (replay protection), flips the parent
 * Verification.status to verified, increments the originating Client's
 * token_version so its next /v1/client poll sees the updated state, and
 * either redirects to the supplied `redirect_url` (if any) or returns a
 * minimal JSON envelope. The originating device's polled SignIn/SignUp
 * attempt picks up the verified Verification on its next call.
 *
 * Re-clicking the same link returns 410 — the consumed VerificationCode
 * is the replay-protection guard.
 */
final class MagicLinkController
{
    public function redeem(Request $request, MagicLinkVerifier $verifier): JsonResponse|RedirectResponse
    {
        $env = app(Environment::class);
        $jwt = $request->query('__authn_magic_link');
        if (! is_string($jwt) || $jwt === '') {
            return $this->error(400, 'magic_link_missing', 'A magic link token is required.');
        }

        $result = $verifier->verify($jwt, $env);
        if ($result['error'] !== null) {
            $status = match ($result['error']) {
                'expired', 'consumed' => 410,
                default => 400,
            };

            return $this->error($status, 'magic_link_'.$result['error'], 'The magic link is '.$result['error'].'.');
        }

        $attempt = $result['attempt'];
        if ($attempt instanceof SignInAttempt || $attempt instanceof SignUpAttempt) {
            Client::query()
                ->withoutGlobalScopes()
                ->where('id', (string) $attempt->client_id)
                ->update([
                    'token_version' => DB::raw('token_version + 1'),
                    'last_active_at' => now(),
                ]);
        }

        $redirectUrl = $request->query('redirect_url');
        if (is_string($redirectUrl) && $redirectUrl !== '') {
            return redirect()->away($redirectUrl);
        }

        return response()->json([
            'object' => 'magic_link_redemption',
            'verified' => true,
        ])->header('Cache-Control', 'no-store');
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
