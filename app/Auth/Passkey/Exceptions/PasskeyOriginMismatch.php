<?php

declare(strict_types=1);

namespace App\Auth\Passkey\Exceptions;

final class PasskeyOriginMismatch extends PasskeyException
{
    public function code(): string
    {
        return 'passkey_origin_mismatch';
    }
}
