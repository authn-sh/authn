<?php

declare(strict_types=1);

namespace App\Services\Verification;

use Illuminate\Support\Str;

/**
 * Mints the cleartext values that get hashed into VerificationCode rows
 * and dispatched to end users (email/SMS code, magic-link token).
 *
 * No randomness happens at the model layer — services that need a code
 * (sign-in, sign-up, /v1/me email-add) call into here.
 */
final class CodeGenerator
{
    /** Default OTP length per PLAN §9.5. */
    public const DEFAULT_NUMERIC_LENGTH = 6;

    /**
     * 6-digit numeric code, cryptographically random.
     */
    public function generateNumericCode(int $length = self::DEFAULT_NUMERIC_LENGTH): string
    {
        if ($length < 4 || $length > 12) {
            throw new \InvalidArgumentException('Numeric code length must be between 4 and 12 digits.');
        }

        $max = 10 ** $length;
        $value = random_int(0, $max - 1);

        return str_pad((string) $value, $length, '0', STR_PAD_LEFT);
    }

    /**
     * 32-byte URL-safe magic-link token. Surfaces inside the
     * `?__authn_ticket=` query parameter (PLAN §9.7) — the token format
     * matches the JWT shape there but the value here is a placeholder
     * for the JWT issuer that AU-13's TicketIssuer wraps.
     */
    public function generateMagicLinkToken(): string
    {
        return Str::random(43); // ~32 bytes of base62 entropy
    }

    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
