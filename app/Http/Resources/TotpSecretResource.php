<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TotpSecret;

/**
 * Public TotpSecret shape per openapi components/schemas/TotpSecret.yaml.
 * `secret` / `otpauth_uri` / `qr_code_data_url` are surfaced **only** on
 * the enrolment response — set `secret`, `otpauthUri`, `qrCodeDataUrl`
 * on the call. Subsequent reads (`GET`, `verify`, `destroy`) pass the
 * defaults and emit `null` for those fields.
 */
final class TotpSecretResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(
        TotpSecret $secret,
        ?string $exposedSecret = null,
        ?string $otpauthUri = null,
        ?string $qrCodeDataUrl = null,
    ): array {
        return [
            'object' => 'totp_secret',
            'id' => $secret->id,
            'user_id' => $secret->user_id,
            'secret' => $exposedSecret,
            'otpauth_uri' => $otpauthUri,
            'qr_code_data_url' => $qrCodeDataUrl,
            'verified_at' => $secret->verified_at?->getTimestampMs(),
            'created_at' => $secret->created_at?->getTimestampMs(),
            'updated_at' => $secret->updated_at?->getTimestampMs(),
        ];
    }
}
