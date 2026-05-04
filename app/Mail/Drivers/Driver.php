<?php

declare(strict_types=1);

namespace App\Mail\Drivers;

use App\Mail\Envelope;
use App\Mail\Receipt;

interface Driver
{
    public function name(): string;

    public function send(Envelope $envelope): Receipt;
}
