<?php

declare(strict_types=1);

namespace App\Auth\Oauth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Server-issued `state` parameter shipped to the IdP and bounced back on
 * the callback. Carries enough plumbing to find the originating
 * SignIn/SignUp attempt without trusting the URL's other parameters.
 *
 * Encrypted at rest via Laravel's app-key (Crypt::encrypt). Tamper-proof
 * on round-trip — any modification breaks decryption and the callback
 * controller surfaces a uniform `state_invalid` error.
 */
final class StateToken
{
    public const TTL_SECONDS = 600;

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function mint(
        string $environmentId,
        string $providerKey,
        string $verificationId,
        string $clientId,
        string $attemptId,
        string $attemptKind,
        ?string $redirectUrl,
        ?string $redirectUrlComplete,
        string $nonce,
        array $extra = [],
    ): string {
        $payload = [
            'env' => $environmentId,
            'pk' => $providerKey,
            'v' => $verificationId,
            'c' => $clientId,
            'a' => $attemptId,
            'ak' => $attemptKind,
            'ru' => $redirectUrl,
            'ruc' => $redirectUrlComplete,
            'n' => $nonce,
            'iat' => time(),
            'exp' => time() + self::TTL_SECONDS,
        ] + $extra;

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function unwrap(string $token): ?array
    {
        try {
            $raw = Crypt::decryptString($token);
        } catch (DecryptException) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return null;
        }
        if (! isset($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }
}
