<?php

declare(strict_types=1);

namespace App\Auth\Saml;

use RuntimeException;

/**
 * Thrown by `SamlConnectionService::parseSamlResponse` when an inbound
 * SAML 2.0 response fails any spec check: signature mismatch, expired
 * `NotOnOrAfter`, wrong `Audience`, replayed `InResponseTo`.
 */
final class SamlException extends RuntimeException
{
    public const REASON_SIGNATURE = 'saml_signature_invalid';

    public const REASON_AUDIENCE = 'saml_audience_mismatch';

    public const REASON_EXPIRED = 'saml_assertion_expired';

    public const REASON_REPLAY = 'saml_assertion_replay';

    public const REASON_MALFORMED = 'saml_response_malformed';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message === '' ? $reason : $message);
    }
}
