<?php

declare(strict_types=1);

namespace App\Mail\Drivers;

use App\Mail\Envelope;
use App\Mail\Receipt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Postmark HTTP driver (https://postmarkapp.com/developer/api/email-api).
 *
 * Authenticated by the `X-Postmark-Server-Token` header.
 */
final class PostmarkDriver implements Driver
{
    public const NAME = 'postmark';

    public function name(): string
    {
        return self::NAME;
    }

    public function send(Envelope $envelope): Receipt
    {
        $apiKey = (string) config('authn-mail.drivers.postmark.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('POSTMARK_API_KEY is not configured.');
        }
        $endpoint = (string) config('authn-mail.drivers.postmark.endpoint');
        $stream = (string) config('authn-mail.drivers.postmark.message_stream');

        $payload = [
            'From' => sprintf('%s <%s>', $envelope->fromName, $envelope->fromEmail),
            'To' => $envelope->toEmail,
            'Subject' => $envelope->subject,
            'HtmlBody' => $envelope->html,
            'TextBody' => $envelope->text,
            'MessageStream' => $stream,
        ];
        if ($envelope->replyTo !== null) {
            $payload['ReplyTo'] = $envelope->replyTo;
        }
        if ($envelope->headers !== []) {
            $payload['Headers'] = array_map(
                fn ($name, $value) => ['Name' => (string) $name, 'Value' => (string) $value],
                array_keys($envelope->headers),
                array_values($envelope->headers),
            );
        }

        $response = Http::withHeaders([
            'X-Postmark-Server-Token' => $apiKey,
            'Accept' => 'application/json',
        ])->asJson()->post($endpoint, $payload);

        $body = $response->json();
        $accepted = $response->successful() && is_array($body) && (int) ($body['ErrorCode'] ?? -1) === 0;

        return new Receipt(
            id: is_array($body) ? ($body['MessageID'] ?? null) : null,
            driver: self::NAME,
            accepted: $accepted,
            meta: ['status' => $response->status()],
        );
    }
}
