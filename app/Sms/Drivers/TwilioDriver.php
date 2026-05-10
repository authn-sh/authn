<?php

declare(strict_types=1);

namespace App\Sms\Drivers;

use App\Sms\SmsEnvelope;
use App\Sms\SmsReceipt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Twilio REST driver. Authenticated via HTTP Basic with the
 * `(account_sid, auth_token)` pair. Endpoint URL contains an
 * `{AccountSid}` placeholder so config can stay literal.
 */
final class TwilioDriver implements SmsDriver
{
    public const NAME = 'twilio';

    public function name(): string
    {
        return self::NAME;
    }

    public function send(SmsEnvelope $envelope): SmsReceipt
    {
        $sid = (string) config('authn-sms.drivers.twilio.account_sid');
        $token = (string) config('authn-sms.drivers.twilio.auth_token');
        if ($sid === '' || $token === '') {
            throw new RuntimeException('Twilio SMS credentials are not configured.');
        }

        $endpoint = str_replace(
            '{AccountSid}',
            $sid,
            (string) config('authn-sms.drivers.twilio.endpoint'),
        );

        $response = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->post($endpoint, [
                'To' => $envelope->toNumber,
                'From' => $envelope->fromNumber,
                'Body' => $envelope->body,
            ]);

        $body = $response->json();
        $accepted = $response->successful() && is_array($body) && ! isset($body['error_code']);

        return new SmsReceipt(
            id: is_array($body) ? ($body['sid'] ?? null) : null,
            driver: self::NAME,
            accepted: $accepted,
            meta: ['status' => $response->status()],
        );
    }
}
