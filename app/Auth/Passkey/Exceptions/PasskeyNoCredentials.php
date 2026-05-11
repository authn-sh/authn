<?php

declare(strict_types=1);

namespace App\Auth\Passkey\Exceptions;

final class PasskeyNoCredentials extends PasskeyException
{
    public function code(): string
    {
        return 'passkey_no_credentials';
    }
}
