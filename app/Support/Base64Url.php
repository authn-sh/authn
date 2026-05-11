<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RFC 4648 §5 base64url: URL-safe alphabet, no padding. Used for surfacing
 * raw WebAuthn `credential_id` / `challenge` bytes over JSON without having
 * to round-trip through hex.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $padLen = (4 - strlen($padded) % 4) % 4;
        if ($padLen > 0) {
            $padded .= str_repeat('=', $padLen);
        }
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}
