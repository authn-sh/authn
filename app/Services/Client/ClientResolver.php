<?php

declare(strict_types=1);

namespace App\Services\Client;

use App\Models\Client;
use App\Models\Environment;
use App\Support\Id;

/**
 * Issues and resolves the `__client` cookie that identifies one device's
 * Client row across requests.
 *
 * Cookie format: `<client_id>.<base64url(hmac_sha256(secret, client_id))>`
 *   - The plaintext `client_id` is in the cookie so we know which row to
 *     load without a reverse-lookup on the HMAC.
 *   - The HMAC is computed with the row's `cookie_secret` (rotated on
 *     `Client::create`); a tampered or forged cookie won't HMAC-match.
 *   - Constant-time compare via `hash_equals` mitigates timing leaks.
 *
 * The cookie itself is HttpOnly + SameSite=Lax + Secure (production).
 * That hardening + the per-row secret + token-id-in-cookie design follows
 * the pattern documented in PLAN §6.3.
 */
final class ClientResolver
{
    /**
     * Verify a cookie value and return the matching Client, or null on
     * any failure mode (missing, malformed, unknown row, HMAC mismatch,
     * cross-environment).
     */
    public function fromCookie(?string $cookieValue, Environment $environment): ?Client
    {
        if ($cookieValue === null || $cookieValue === '') {
            return null;
        }

        $dot = strpos($cookieValue, '.');
        if ($dot === false) {
            return null;
        }

        $clientId = substr($cookieValue, 0, $dot);
        $signature = substr($cookieValue, $dot + 1);

        if (! Id::isValid($clientId) || $signature === '') {
            return null;
        }

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('id', $clientId)
            ->where('environment_id', $environment->id)
            ->first();

        if ($client === null) {
            return null;
        }

        $expected = $this->computeSignature($client->id, $client->cookie_secret);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        return $client;
    }

    /**
     * Build the cookie value to set on the response after creating /
     * touching a Client row.
     */
    public function mintCookieValue(Client $client): string
    {
        return $client->id.'.'.$this->computeSignature($client->id, $client->cookie_secret);
    }

    private function computeSignature(string $clientId, string $secret): string
    {
        return $this->base64Url(hash_hmac('sha256', $clientId, $secret, true));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
