<?php

declare(strict_types=1);

namespace App\Auth\Passkey\Exceptions;

final class PasskeyAttestationInvalid extends PasskeyException
{
    public function code(): string
    {
        return 'passkey_attestation_invalid';
    }
}
