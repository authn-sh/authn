<?php

declare(strict_types=1);

namespace App\Sms;

/**
 * Immutable struct passed from the pipeline into a `SmsDriver::send()`.
 * Mirror of `App\Mail\Envelope`.
 */
final class SmsEnvelope
{
    public function __construct(
        public readonly string $toNumber,
        public readonly string $fromNumber,
        public readonly string $body,
        public readonly string $templateSlug,
    ) {}
}
