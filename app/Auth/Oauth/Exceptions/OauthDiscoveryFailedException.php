<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Exceptions;

use RuntimeException;

/**
 * Raised when `<issuer>/.well-known/openid-configuration` can't be loaded
 * or doesn't validate as an OIDC discovery document. Carries the upstream
 * status code and (truncated) body so the dashboard can surface a useful
 * error to the operator on the AU-13 wizard's Save action.
 */
final class OauthDiscoveryFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $issuer,
        public readonly ?int $statusCode,
        public readonly ?string $upstreamBody,
        string $message,
    ) {
        parent::__construct($message);
    }
}
