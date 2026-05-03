<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Models\Client;
use App\Models\Environment;
use App\Models\SigningKey;
use App\Support\Url;
use Illuminate\Support\Facades\Cache;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;

/**
 * One-shot handshake-token issuance + verification.
 *
 * SSR frameworks (Next.js, Nuxt, Remix) hit `GET /v1/client/handshake?
 * handshake_token=…` after a server-side render to swap a server-issued
 * token for a `__client` cookie they couldn't set themselves. The token
 * is signed with the env's active SigningKey, single-use (jti tracked
 * in the cache for the TTL window), 60s default lifetime.
 *
 * v0.1 ships the verify side — the issuance helper that BAPI calls when
 * an SSR adapter requests a handshake lands later. Tests mint tokens
 * inline.
 */
final class HandshakeToken
{
    public const TTL_SECONDS = 60;

    /** Cache prefix for the single-use jti guard. */
    private const JTI_CACHE_PREFIX = 'handshake:jti:';

    /**
     * Mint a handshake token bound to a specific Client.
     */
    public function issue(Environment $environment, Client $client): string
    {
        $signingKey = $environment->signingKeys()
            ->where('status', SigningKey::STATUS_ACTIVE)
            ->latest('activated_at')
            ->firstOrFail();

        $now = now();
        $expiresAt = $now->copy()->addSeconds(self::TTL_SECONDS);

        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($signingKey->privatePem()),
            InMemory::plainText('public-not-needed-for-signing'),
        );

        $token = $config->builder()
            ->withHeader('kid', $signingKey->id)
            ->issuedBy(Url::fapi($environment, ''))
            ->relatedTo($client->id)
            ->identifiedBy($this->mintJti())
            ->issuedAt($now->toDateTimeImmutable())
            ->canOnlyBeUsedAfter($now->toDateTimeImmutable())
            ->expiresAt($expiresAt->toDateTimeImmutable())
            ->withClaim('purpose', 'handshake')
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }

    /**
     * Verify a handshake token. Returns the resolved Client on success
     * or null on any failure mode (bad signature, expired, replayed,
     * cross-environment, missing client row).
     */
    public function verify(string $jwt, Environment $environment): ?Client
    {
        try {
            $token = (new Parser(new JoseEncoder))->parse($jwt);
        } catch (\Throwable) {
            return null;
        }

        if (! $token instanceof Plain) {
            return null;
        }

        $kid = (string) ($token->headers()->get('kid') ?? '');
        if ($kid === '') {
            return null;
        }

        $signingKey = $environment->signingKeys()
            ->where('id', $kid)
            ->whereIn('status', [
                SigningKey::STATUS_ACTIVE,
                SigningKey::STATUS_RETIRING,
                SigningKey::STATUS_PENDING,
            ])
            ->first();
        if ($signingKey === null) {
            return null;
        }

        $publicResource = openssl_pkey_get_private($signingKey->privatePem());
        $publicPem = openssl_pkey_get_details($publicResource)['key'];

        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText('private-not-needed-for-verification'),
            InMemory::plainText($publicPem),
        );

        try {
            (new Validator)->assert(
                $token,
                new SignedWith($config->signer(), $config->verificationKey()),
                new IssuedBy(Url::fapi($environment, '')),
                new StrictValidAt(SystemClock::fromSystemTimezone()),
            );
        } catch (\Throwable) {
            return null;
        }

        $jti = $token->claims()->get('jti');
        if (! is_string($jti) || $jti === '') {
            return null;
        }
        $cacheKey = self::JTI_CACHE_PREFIX.$environment->id.':'.$jti;
        if (! Cache::add($cacheKey, 1, self::TTL_SECONDS)) {
            return null; // replay
        }

        $purpose = $token->claims()->get('purpose');
        if ($purpose !== 'handshake') {
            return null;
        }

        $clientId = $token->claims()->get('sub');
        if (! is_string($clientId)) {
            return null;
        }

        return Client::query()
            ->withoutGlobalScopes()
            ->where('id', $clientId)
            ->where('environment_id', $environment->id)
            ->first();
    }

    private function mintJti(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
