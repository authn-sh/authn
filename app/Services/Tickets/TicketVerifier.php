<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\Environment;
use App\Models\SigningKey;
use App\Support\Url;
use Illuminate\Support\Facades\Cache;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;

/**
 * Verifies + redeems `__authn_ticket` JWTs (PLAN §9.7 / §9.8).
 *
 * Responsibilities:
 *   - Parse the JWT and locate its kid in the env's published key set.
 *   - Validate signature, issuer (FAPI URL), audience ("ticket"), clock.
 *   - Single-use guard: the jti is cached at redemption time; replays
 *     return null.
 *   - Returns the decoded claims map on success or null on any failure.
 *
 * The "purpose" claim discriminates which downstream flow handles
 * the ticket (invitation, sign_in_token, …) — that policy lives in
 * the controllers, not here.
 */
final class TicketVerifier
{
    private const JTI_CACHE_PREFIX = 'ticket:jti:';

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

        $publicResource = openssl_pkey_get_private($signingKey->privatePem());
        $publicPem = openssl_pkey_get_details($publicResource)['key'];

        try {
            (new Validator)->assert(
                $token,
                new SignedWith(new Sha256, InMemory::plainText($publicPem)),
                new IssuedBy(Url::fapi($environment, '')),
                new PermittedFor('ticket'),
                new StrictValidAt(SystemClock::fromSystemTimezone()),
            );
        } catch (\Throwable) {
            return null;
        }

        $jti = $token->claims()->get('jti');
        if (! is_string($jti) || $jti === '') {
            return null;
        }

        $expiresAt = $token->claims()->get('exp');
        $ttl = $expiresAt instanceof \DateTimeInterface
            ? max(60, $expiresAt->getTimestamp() - now()->getTimestamp() + 60)
            : 60 * 60;

        $cacheKey = self::JTI_CACHE_PREFIX.$environment->id.':'.$jti;
        if (! Cache::add($cacheKey, 1, $ttl)) {
            return null; // replay
        }

        return $token->claims()->all();
    }
}
