<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use RuntimeException;

/**
 * Tiny client for the Mailpit HTTP API. The dev compose stack runs Mailpit
 * at http://mailpit:8025 (or http://localhost:8025 from the host). Dusk
 * tests use it to read the verification code that authn just emailed,
 * without depending on a real SMTP capture or a Laravel-side fake.
 */
final class Mailpit
{
    public function __construct(private readonly string $baseUrl) {}

    public static function default(): self
    {
        return new self((string) (
            $_ENV['DUSK_MAILPIT_URL']
            ?? env('DUSK_MAILPIT_URL')
            ?? 'http://mailpit:8025'
        ));
    }

    public function clear(): void
    {
        $this->request('DELETE', '/api/v1/messages');
    }

    /**
     * Poll for the most recent message addressed to `$recipient` and return
     * its first 6-digit code. Retries up to ~5s to absorb queue latency.
     */
    public function fetchVerificationCode(string $recipient, int $timeoutSeconds = 5): string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastBody = '';
        while (microtime(true) < $deadline) {
            $messages = $this->messagesTo($recipient);
            if (! empty($messages)) {
                $body = $this->body((string) $messages[0]['ID']);
                $lastBody = $body;
                if (preg_match('/\b(\d{6})\b/', $body, $m)) {
                    return $m[1];
                }
            }
            usleep(250_000);
        }

        throw new RuntimeException('No verification code found for '.$recipient.' within '.$timeoutSeconds.'s. Last body: '.substr($lastBody, 0, 200));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messagesTo(string $recipient): array
    {
        $payload = json_decode(
            $this->request('GET', '/api/v1/messages?query='.rawurlencode('to:'.$recipient)),
            true
        );

        return is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
    }

    private function body(string $id): string
    {
        $payload = json_decode($this->request('GET', '/api/v1/message/'.$id), true);

        return (string) ($payload['Text'] ?? $payload['HTML'] ?? '');
    }

    private function request(string $method, string $path): string
    {
        $ch = curl_init($this->baseUrl.$path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException("Mailpit {$method} {$path} → {$status}");
        }

        return (string) $body;
    }
}
