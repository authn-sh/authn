<?php

declare(strict_types=1);

namespace App\Auth\Oauth;

use App\Models\OauthProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as Rs256;
use Lcobucci\JWT\Signer\Rsa\Sha384 as Rs384;
use Lcobucci\JWT\Signer\Rsa\Sha512 as Rs512;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Throwable;

/**
 * Validates IdP-issued id_tokens against the provider's published JWKS.
 *
 * For preset OAuth providers (Google/Apple/Microsoft) the id_token JWS is
 * the spec-conformant authoritative source of `sub` + `email` +
 * `email_verified`. Userinfo can be tampered with by an attacker who
 * controls the userinfo endpoint or a malicious IdP; the id_token can't
 * (its signature is verified against the JWKS).
 *
 * GitHub does not issue an id_token, so callers should treat a missing
 * id_token + missing jwks_uri as "no validation possible" and fall back
 * to userinfo — that's the same posture v0.3 shipped.
 *
 * JWKS responses are cached for 1 hour per (provider_id, jwks_uri).
 */
final class IdTokenValidator
{
    private const JWKS_CACHE_TTL_SECONDS = 3600;

    private readonly Parser $parser;

    private readonly Validator $validator;

    public function __construct()
    {
        $this->parser = new Parser(new JoseEncoder);
        $this->validator = new Validator;
    }

    /**
     * @return array<string,mixed>|null Validated claims, or null when validation fails / not applicable.
     */
    public function validate(OauthProvider $provider, ResolvedProvider $resolved, string $idToken): ?array
    {
        if ($resolved->jwksUri === null) {
            return null;
        }

        try {
            $token = $this->parser->parse($idToken);
        } catch (Throwable) {
            return null;
        }
        if (! $token instanceof Plain) {
            return null;
        }

        $kid = $token->headers()->get('kid');
        $alg = (string) $token->headers()->get('alg', '');
        if (! in_array($alg, $resolved->idTokenSigningAlgs ?: ['RS256'], true)) {
            return null;
        }

        $jwks = $this->fetchJwks($provider->id, $resolved->jwksUri);
        if ($jwks === null) {
            return null;
        }

        $key = $this->pickKey($jwks, is_string($kid) ? $kid : null);
        if ($key === null) {
            return null;
        }

        $signer = match ($alg) {
            'RS256' => new Rs256,
            'RS384' => new Rs384,
            'RS512' => new Rs512,
            default => null,
        };
        if ($signer === null) {
            return null;
        }

        $constraints = [new SignedWith($signer, InMemory::plainText($key))];
        if ($resolved->issuer !== null && $resolved->issuer !== '') {
            $constraints[] = new IssuedBy($resolved->issuer);
        }
        if ($provider->client_id !== null && $provider->client_id !== '') {
            $constraints[] = new PermittedFor($provider->client_id);
        }
        $constraints[] = new LooseValidAt(SystemClock::fromUTC());

        if (! $this->validator->validate($token, ...$constraints)) {
            return null;
        }

        $claims = $token->claims()->all();

        return is_array($claims) ? $claims : null;
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchJwks(string $providerId, string $jwksUri): ?array
    {
        $cacheKey = 'oauth:jwks:'.$providerId.':'.hash('sha256', $jwksUri);

        return Cache::remember($cacheKey, self::JWKS_CACHE_TTL_SECONDS, function () use ($jwksUri): ?array {
            try {
                $response = Http::timeout(5)->get($jwksUri);
            } catch (ConnectionException|RequestException|Throwable) {
                return null;
            }
            if (! $response->successful()) {
                return null;
            }
            $body = $response->json();
            $keys = $body['keys'] ?? null;
            if (! is_array($keys)) {
                return null;
            }

            return array_values(array_filter($keys, 'is_array'));
        });
    }

    /**
     * @param  array<int,array<string,mixed>>  $jwks
     */
    private function pickKey(array $jwks, ?string $kid): ?string
    {
        foreach ($jwks as $jwk) {
            if ($kid !== null && ($jwk['kid'] ?? null) !== $kid) {
                continue;
            }
            if (($jwk['kty'] ?? null) !== 'RSA') {
                continue;
            }
            $n = $jwk['n'] ?? null;
            $e = $jwk['e'] ?? null;
            if (! is_string($n) || ! is_string($e)) {
                continue;
            }

            return $this->jwkToPem($n, $e);
        }

        return null;
    }

    /**
     * Convert an RSA JWK (n/e base64url) to a PEM-encoded public key.
     * The DER structure is built by hand — keeps us free of phpseclib.
     */
    private function jwkToPem(string $nB64Url, string $eB64Url): string
    {
        $n = $this->base64urlDecode($nB64Url);
        $e = $this->base64urlDecode($eB64Url);

        $modulus = $this->encodeAsn1Integer($n);
        $exponent = $this->encodeAsn1Integer($e);
        $rsaKey = $this->encodeAsn1Sequence($modulus.$exponent);
        $bitString = "\x00".$rsaKey;
        $bitStringWrap = "\x03".$this->encodeLength(strlen($bitString)).$bitString;
        // RSA OID (1.2.840.113549.1.1.1) + NULL
        $algIdentifier = $this->encodeAsn1Sequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"
        );
        $spki = $this->encodeAsn1Sequence($algIdentifier.$bitStringWrap);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private function encodeAsn1Integer(string $bytes): string
    {
        if ($bytes !== '' && (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->encodeLength(strlen($bytes)).$bytes;
    }

    private function encodeAsn1Sequence(string $bytes): string
    {
        return "\x30".$this->encodeLength(strlen($bytes)).$bytes;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function base64urlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}
