<?php

declare(strict_types=1);

namespace App\Services\Keys;

use App\Models\Environment;
use App\Models\SigningKey;
use App\Support\Id;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Mints fresh RS256 keypairs for an Environment and persists them as
 * SigningKey rows. The full lifecycle (pending → active → retiring →
 * expired) is owned by the rotation cron in AU-19; this service just
 * produces a new row in the requested status.
 */
final class SigningKeyGenerator
{
    public const KEY_BITS = 2048;

    public function generate(Environment $environment, string $status = SigningKey::STATUS_ACTIVE): SigningKey
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new RuntimeException('Failed to generate RSA keypair: '.(openssl_error_string() ?: 'unknown'));
        }

        if (! openssl_pkey_export($resource, $privatePem)) {
            throw new RuntimeException('Failed to export private PEM: '.(openssl_error_string() ?: 'unknown'));
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('Failed to read RSA modulus / exponent: '.(openssl_error_string() ?: 'unknown'));
        }

        $kid = (new SigningKey)->getAttribute('id') ?: Id::generate('kid_');
        $jwk = $this->jwk($details['rsa']['n'], $details['rsa']['e'], $kid);

        $now = now();
        $key = new SigningKey([
            'environment_id' => $environment->id,
            'algorithm' => 'RS256',
            'public_jwk' => $jwk,
            'encrypted_private_pem' => Crypt::encryptString($privatePem),
            'status' => $status,
            'published_at' => $now,
            'activated_at' => $status === SigningKey::STATUS_ACTIVE ? $now : null,
        ]);
        $key->id = $kid;
        $key->save();

        return $key;
    }

    /**
     * Build a JWK from the raw RSA modulus + exponent bytes returned by
     * openssl_pkey_get_details. Encoding is base64url per RFC 7517 §3.
     *
     * @return array{kty:string,alg:string,use:string,kid:string,n:string,e:string}
     */
    private function jwk(string $modulus, string $exponent, string $kid): array
    {
        return [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $kid,
            'n' => $this->base64Url($modulus),
            'e' => $this->base64Url($exponent),
        ];
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
