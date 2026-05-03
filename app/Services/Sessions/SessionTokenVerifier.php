<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Models\Environment;
use App\Models\SigningKey;
use App\Support\Url;
use Lcobucci\Clock\SystemClock;
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
 * Verifies a `__session` JWT against an environment's published JWKS.
 *
 * Used by:
 *   - the FAPI session-token mint to sanity-check what it just issued
 *     (defence in depth);
 *   - test code that needs to inspect a token's claims;
 *   - AU-12's `/v1/me` middleware (when it lands; for now BAPI / FAPI
 *     surfaces validate via `sdk-php` against JWKS).
 *
 * Returns the decoded claims map on success or null on any failure mode
 * (bad signature, expired, wrong issuer, unknown kid).
 */
final class SessionTokenVerifier
{
    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $jwt, Environment $environment): ?array
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

        $publicPem = $this->extractPublicPem($signingKey);
        $constraints = [
            new SignedWith(new Sha256, InMemory::plainText($publicPem)),
            new IssuedBy(Url::fapi($environment, '')),
            new StrictValidAt(SystemClock::fromSystemTimezone()),
        ];

        try {
            (new Validator)->assert($token, ...$constraints);
        } catch (\Throwable) {
            return null;
        }

        return array_merge(
            $token->claims()->all(),
            ['_kid' => $kid],
        );
    }

    private function extractPublicPem(SigningKey $signingKey): string
    {
        // Re-derive the public PEM from the private PEM so we don't have to
        // store both. AU-19's rotation cron will eventually cache the public
        // PEM separately.
        $resource = openssl_pkey_get_private($signingKey->privatePem());
        $details = openssl_pkey_get_details($resource);

        return $details['key'];
    }
}
