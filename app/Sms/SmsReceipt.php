<?php

declare(strict_types=1);

namespace App\Sms;

/**
 * Outcome of a single `SmsDriver::send()`. Mirror of `App\Mail\Receipt`.
 */
final class SmsReceipt
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $driver,
        public readonly bool $accepted,
        public readonly array $meta = [],
    ) {}
}
