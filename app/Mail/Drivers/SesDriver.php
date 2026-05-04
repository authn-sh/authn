<?php

declare(strict_types=1);

namespace App\Mail\Drivers;

use App\Mail\Envelope;
use App\Mail\Receipt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SES v2 SendEmail driver. Signed via SigV4 manually so we don't need to
 * pull the AWS PHP SDK (one HTTP call doesn't justify the dependency tree).
 */
final class SesDriver implements Driver
{
    public const NAME = 'ses';

    public function name(): string
    {
        return self::NAME;
    }

    public function send(Envelope $envelope): Receipt
    {
        $cfg = (array) config('authn-mail.drivers.ses');
        $region = (string) ($cfg['region'] ?? '');
        $accessKey = (string) ($cfg['access_key_id'] ?? '');
        $secret = (string) ($cfg['secret_access_key'] ?? '');
        if ($accessKey === '' || $secret === '' || $region === '') {
            throw new RuntimeException('SES driver requires AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, and AWS_REGION.');
        }

        $host = "email.{$region}.amazonaws.com";
        $endpoint = "https://{$host}/v2/email/outbound-emails";

        $payload = json_encode([
            'FromEmailAddress' => sprintf('%s <%s>', $envelope->fromName, $envelope->fromEmail),
            'Destination' => ['ToAddresses' => [$envelope->toEmail]],
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $envelope->subject, 'Charset' => 'UTF-8'],
                    'Body' => [
                        'Html' => ['Data' => $envelope->html, 'Charset' => 'UTF-8'],
                        'Text' => ['Data' => $envelope->text, 'Charset' => 'UTF-8'],
                    ],
                ],
            ],
            'ReplyToAddresses' => $envelope->replyTo !== null ? [$envelope->replyTo] : [],
        ], JSON_THROW_ON_ERROR);

        $headers = $this->signRequest($accessKey, $secret, $region, $host, $payload);

        $response = Http::withHeaders($headers)
            ->withBody($payload, 'application/json')
            ->post($endpoint);

        $body = $response->json();

        return new Receipt(
            id: is_array($body) ? ($body['MessageId'] ?? null) : null,
            driver: self::NAME,
            accepted: $response->successful(),
            meta: ['status' => $response->status()],
        );
    }

    /**
     * Minimal SigV4 implementation for the single SES SendEmail endpoint.
     *
     * @return array<string, string>
     */
    private function signRequest(string $accessKey, string $secret, string $region, string $host, string $payload): array
    {
        $service = 'ses';
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);

        $payloadHash = hash('sha256', $payload);
        $canonicalHeaders = "content-type:application/json\nhost:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$now}\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "POST\n/v2/email/outbound-emails\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $credentialScope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$credentialScope}\n".hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $date, 'AWS4'.$secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return [
            'Content-Type' => 'application/json',
            'Host' => $host,
            'X-Amz-Content-Sha256' => $payloadHash,
            'X-Amz-Date' => $now,
            'Authorization' => $authorization,
        ];
    }
}
