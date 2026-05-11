<?php

declare(strict_types=1);

namespace App\Auth\Passkey\Exceptions;

final class PasskeyAssertionInvalid extends PasskeyException
{
    public function code(): string
    {
        return 'passkey_assertion_invalid';
    }
}
