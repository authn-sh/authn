<?php

declare(strict_types=1);

namespace App\Sms\Drivers;

use App\Sms\SmsEnvelope;
use App\Sms\SmsReceipt;
use Illuminate\Support\Facades\Log;

/**
 * No-op driver — logs the rendered body and returns a success Receipt.
 * Default in dev / CI so workers don't try to hit a live SMS gateway.
 */
final class NullDriver implements SmsDriver
{
    public const NAME = 'null';

    public function name(): string
    {
        return self::NAME;
    }

    public function send(SmsEnvelope $envelope): SmsReceipt
    {
        Log::info('sms.null_driver.send', [
            'to' => $envelope->toNumber,
            'from' => $envelope->fromNumber,
            'template' => $envelope->templateSlug,
            'body' => $envelope->body,
        ]);

        return new SmsReceipt(
            id: null,
            driver: self::NAME,
            accepted: true,
            meta: ['logged' => true],
        );
    }
}
