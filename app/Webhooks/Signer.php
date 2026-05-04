<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Models\WebhookEndpoint;

/**
 * HMAC-SHA256 signer matching the svix wire format
 * (https://docs.svix.com/receiving/verifying-payloads).
 *
 * Signature header is a space-separated list of `v1,<base64>` segments.
 * During the rotation window we emit two segments — current secret first,
 * prior secret second — so consumers verifying with either secret accept
 * the request.
 */
final class Signer
{
    public const SCHEME = 'v1';

    /**
     * Compute one segment.
     */
    public function sign(string $body, string $messageId, int $timestamp, string $secret): string
    {
        $payload = "{$messageId}.{$timestamp}.{$body}";
        $sig = hash_hmac('sha256', $payload, $secret, true);

        return self::SCHEME.','.base64_encode($sig);
    }

    /**
     * Build the full `svix-signature` header value for an endpoint, honouring
     * the rotation window.
     */
    public function headerFor(WebhookEndpoint $endpoint, string $body, string $messageId, int $timestamp): string
    {
        $segments = [$this->sign($body, $messageId, $timestamp, (string) $endpoint->signing_secret)];

        if ($endpoint->prior_signing_secret !== null
            && $endpoint->prior_signing_secret_expires_at !== null
            && $endpoint->prior_signing_secret_expires_at->isFuture()
        ) {
            $segments[] = $this->sign($body, $messageId, $timestamp, (string) $endpoint->prior_signing_secret);
        }

        return implode(' ', $segments);
    }

    /**
     * Constant-time verification — useful for tests and any future inbound
     * verifier (we don't ship one in v0.1, but keeping the helper symmetric).
     *
     * @param  list<string>  $candidateSecrets
     */
    public function verify(string $body, string $messageId, int $timestamp, string $headerValue, array $candidateSecrets): bool
    {
        $given = explode(' ', $headerValue);
        foreach ($candidateSecrets as $secret) {
            $expected = $this->sign($body, $messageId, $timestamp, $secret);
            foreach ($given as $segment) {
                if (hash_equals($expected, trim($segment))) {
                    return true;
                }
            }
        }

        return false;
    }
}
