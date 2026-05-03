<?php

declare(strict_types=1);

namespace App\Auth\SignUp;

use App\Models\Environment;
use App\Models\Invitation;
use App\Services\Tickets\TicketVerifier;

/**
 * Single-call redemption of an invitation `ticket` JWT against an env.
 * Returns either:
 *   ['ok' => true,  'invitation' => Invitation, 'email' => string, 'metadata' => array, 'redirect_url' => ?string]
 *   ['ok' => false, 'reason' => string]   (reason is a stable error code)
 *
 * Reasons:
 *   - ticket_invalid   — JWT failed signature / clock / replay / missing claims
 *   - ticket_expired   — invitation row past its expires_at (or status != pending)
 *
 * The verifier already hardcodes the single-use jti guard, so a replay of the
 * same ticket returns ticket_invalid.
 */
final class TicketRedeemer
{
    public const REASON_INVALID = 'ticket_invalid';

    public const REASON_EXPIRED = 'ticket_expired';

    public function __construct(private readonly TicketVerifier $verifier) {}

    /**
     * @return array{ok: bool, reason?: string, invitation?: Invitation, email?: string, metadata?: array<string, mixed>, redirect_url?: ?string}
     */
    public function redeem(Environment $environment, string $jwt): array
    {
        $claims = $this->verifier->verify($jwt, $environment);
        if ($claims === null) {
            return ['ok' => false, 'reason' => self::REASON_INVALID];
        }

        if (($claims['purpose'] ?? null) !== 'invitation') {
            return ['ok' => false, 'reason' => self::REASON_INVALID];
        }

        $invitationId = $claims['sid'] ?? null;
        if (! is_string($invitationId) || $invitationId === '') {
            return ['ok' => false, 'reason' => self::REASON_INVALID];
        }

        $invitation = Invitation::query()
            ->withoutGlobalScopes()
            ->where('id', $invitationId)
            ->where('environment_id', $environment->id)
            ->first();
        if ($invitation === null) {
            return ['ok' => false, 'reason' => self::REASON_INVALID];
        }
        if (! $invitation->isRedeemable()) {
            return ['ok' => false, 'reason' => self::REASON_EXPIRED];
        }

        $sub = $claims['sub'] ?? null;
        $email = is_string($sub) ? strtolower($sub) : strtolower($invitation->email_address);
        $metadata = is_array($claims['metadata'] ?? null) ? $claims['metadata'] : [];
        $redirect = is_string($claims['redirect_url'] ?? null) ? $claims['redirect_url'] : null;

        return [
            'ok' => true,
            'invitation' => $invitation,
            'email' => $email,
            'metadata' => $metadata,
            'redirect_url' => $redirect,
        ];
    }
}
