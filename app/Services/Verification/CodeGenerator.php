<?php

declare(strict_types=1);

namespace App\Services\Verification;

/**
 * Mints the cleartext values that get hashed into VerificationCode rows
 * and dispatched to end users (email/SMS codes).
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

    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
