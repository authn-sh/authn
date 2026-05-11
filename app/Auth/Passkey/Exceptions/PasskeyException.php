<?php

declare(strict_types=1);

namespace App\Auth\Passkey\Exceptions;

use RuntimeException;

abstract class PasskeyException extends RuntimeException
{
    /**
     * Stable error code surfaced by the FAPI on 422 responses.
     */
    abstract public function code(): string;
}
