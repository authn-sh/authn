<?php

declare(strict_types=1);

namespace App\Auth\EnterpriseSso;

use RuntimeException;

/**
 * Thrown by `OidcConnectionService::exchangeCode()` when the IdP's
 * token endpoint refuses or returns a malformed response.
 */
final class OidcTokenExchangeException extends RuntimeException
{
    public function __construct(
        public readonly string $connectionId,
        string $detail = '',
    ) {
        parent::__construct("OIDC token exchange failed for connection {$connectionId}: {$detail}");
    }
}
