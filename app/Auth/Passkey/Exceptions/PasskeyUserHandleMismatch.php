<?php

declare(strict_types=1);

namespace App\Auth\Passkey\Exceptions;

final class PasskeyUserHandleMismatch extends PasskeyException
{
    public function code(): string
    {
        return 'passkey_user_handle_mismatch';
    }
}
