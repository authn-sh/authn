<?php

declare(strict_types=1);

namespace App\Mail\Drivers;

use App\Mail\Envelope;
use App\Mail\Receipt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resend HTTP driver (https://resend.com/docs/api-reference/emails/send-email).
 *
 * Authenticated by `Authorization: Bearer <RESEND_API_KEY>`.
 */
final class ResendDriver implements Driver
{
    public const NAME = 'resend';

    public function name(): string
    {
        return self::NAME;
    }

    public function send(Envelope $envelope): Receipt
    {
        $apiKey = (string) config('authn-mail.drivers.resend.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('RESEND_API_KEY is not configured.');
        }
        $endpoint = (string) config('authn-mail.drivers.resend.endpoint');

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, [
                'from' => sprintf('%s <%s>', $envelope->fromName, $envelope->fromEmail),
                'to' => [$envelope->toEmail],
                'subject' => $envelope->subject,
                'html' => $envelope->html,
                'text' => $envelope->text,
                'reply_to' => $envelope->replyTo,
                'headers' => (object) $envelope->headers,
            ]);

        $body = $response->json();
        $accepted = $response->successful() && is_array($body) && isset($body['id']);

        return new Receipt(
            id: is_array($body) ? ($body['id'] ?? null) : null,
            driver: self::NAME,
            accepted: $accepted,
            meta: ['status' => $response->status()],
        );
    }
}
