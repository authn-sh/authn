<?php

declare(strict_types=1);

namespace App\Mail;

final class RenderedEmail
{
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
    ) {}
}
