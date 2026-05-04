<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Result of a Driver::send() call.
 *
 *   - id          provider message id (when surfaced)
 *   - driver      driver name that handled the send
 *   - accepted    true when the provider returned success
 *   - meta        provider-specific extras (status, message_stream, etc.)
 *
 * @phpstan-type ReceiptMeta array<string, mixed>
 */
final class Receipt
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
