<?php

declare(strict_types=1);

namespace App\Auth\Passkey\Exceptions;

final class PasskeyReplayed extends PasskeyException
{
    public function code(): string
    {
        return 'passkey_replayed';
    }
}
