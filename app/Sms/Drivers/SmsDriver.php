<?php

declare(strict_types=1);

namespace App\Sms\Drivers;

use App\Sms\SmsEnvelope;
use App\Sms\SmsReceipt;

interface SmsDriver
{
    public function name(): string;

    public function send(SmsEnvelope $envelope): SmsReceipt;
}
