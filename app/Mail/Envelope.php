<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Driver-agnostic outbound message. Built by App\Mail\Renderer; consumed
 * by App\Mail\Drivers\Driver implementations.
 */
final class Envelope
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $toEmail,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly ?string $replyTo = null,
        public readonly array $headers = [],
    ) {}
}
