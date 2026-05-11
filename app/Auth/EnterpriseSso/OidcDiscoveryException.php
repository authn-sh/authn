<?php

declare(strict_types=1);

namespace App\Auth\EnterpriseSso;

use RuntimeException;

/**
 * Thrown by `OidcConnectionService::discover()` when the IdP's
 * `.well-known/openid-configuration` cannot be fetched or validated.
 */
final class OidcDiscoveryException extends RuntimeException
{
    public function __construct(
        public readonly string $connectionId,
        public readonly string $discoveryUrl,
        string $detail = '',
    ) {
        parent::__construct("OIDC discovery failed for connection {$connectionId} at {$discoveryUrl}: {$detail}");
    }
}
