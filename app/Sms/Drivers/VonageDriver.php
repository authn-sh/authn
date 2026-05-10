<?php

declare(strict_types=1);

namespace App\Sms\Drivers;

use App\Sms\SmsEnvelope;
use App\Sms\SmsReceipt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Vonage (formerly Nexmo) HTTP API driver. Authenticated via
 * `api_key` + `api_secret` form fields per their REST contract.
 */
final class VonageDriver implements SmsDriver
{
    public const NAME = 'vonage';

    public function name(): string
    {
        return self::NAME;
    }

    public function send(SmsEnvelope $envelope): SmsReceipt
    {
        $key = (string) config('authn-sms.drivers.vonage.api_key');
        $secret = (string) config('authn-sms.drivers.vonage.api_secret');
        if ($key === '' || $secret === '') {
            throw new RuntimeException('Vonage SMS credentials are not configured.');
        }

        $endpoint = (string) config('authn-sms.drivers.vonage.endpoint');

        $response = Http::asForm()->post($endpoint, [
            'api_key' => $key,
            'api_secret' => $secret,
            'to' => $envelope->toNumber,
            'from' => $envelope->fromNumber,
            'text' => $envelope->body,
        ]);

        $body = $response->json();
        $messages = is_array($body) && is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $first = $messages[0] ?? null;
        $statusCode = is_array($first) ? (string) ($first['status'] ?? '') : '';
        $accepted = $response->successful() && $statusCode === '0';

        return new SmsReceipt(
            id: is_array($first) ? ($first['message-id'] ?? null) : null,
            driver: self::NAME,
            accepted: $accepted,
            meta: ['status' => $response->status(), 'vonage_status' => $statusCode],
        );
    }
}
