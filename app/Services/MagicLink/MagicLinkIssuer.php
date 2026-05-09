<?php

declare(strict_types=1);

namespace App\Services\MagicLink;

use App\Models\Environment;
use App\Models\SigningKey;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use RuntimeException;

/**
 * Mints `__authn_magic_link` JWTs for the email_link strategy. The token
 * carries `sub: <verification_id>`, `purpose: email_link`, `iat`, `exp`.
 * The sha256 of the JWT is persisted on a new `VerificationCode` so the
 * click-time handler can verify the JWT without storing plaintext, AND
 * detect replay (consumed_at) on a second click.
 */
final class MagicLinkIssuer
{
    public const TTL_SECONDS = 5 * 60;

    public const PURPOSE = 'email_link';

    /**
     * Mint a JWT for the supplied Verification, persist its hash as a
     * VerificationCode, and return the click URL the email should embed.
     *
     * @return array{jwt: string, url: string}
     */
    public function issue(Verification $verification, ?string $redirectUrl = null): array
    {
        $env = $verification->environment;
        if (! $env instanceof Environment) {
            throw new RuntimeException('Verification has no resolvable environment.');
        }

        $signingKey = $env->signingKeys()
            ->where('status', SigningKey::STATUS_ACTIVE)
            ->latest('activated_at')
            ->firstOrFail();
        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($signingKey->privatePem()),
            InMemory::plainText('public-not-needed-for-signing'),
        );

        $now = now();
        $expiresAt = $now->copy()->addSeconds(self::TTL_SECONDS);

        $token = $config->builder()
            ->withHeader('kid', $signingKey->id)
            ->issuedBy(Url::fapi($env, ''))
            ->permittedFor('magic_link')
            ->relatedTo($verification->id)
            ->identifiedBy($this->mintJti())
            ->issuedAt($now->toDateTimeImmutable())
            ->canOnlyBeUsedAfter($now->toDateTimeImmutable())
            ->expiresAt($expiresAt->toDateTimeImmutable())
            ->withClaim('purpose', self::PURPOSE)
            ->getToken($config->signer(), $config->signingKey())
            ->toString();

        DB::transaction(function () use ($verification, $token, $expiresAt): void {
            VerificationCode::query()->create([
                'verification_id' => $verification->id,
                'code_hash' => hash('sha256', $token),
                'purpose' => VerificationCode::PURPOSE_MAGIC_LINK,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);
        });

        return [
            'jwt' => $token,
            'url' => $this->clickUrl($env, $token, $redirectUrl),
        ];
    }

    private function clickUrl(Environment $env, string $jwt, ?string $redirectUrl): string
    {
        $base = Url::fapi($env, '');
        $query = http_build_query(array_filter([
            '__authn_magic_link' => $jwt,
            'redirect_url' => $redirectUrl,
        ], fn ($v): bool => $v !== null && $v !== ''));

        return rtrim($base, '/').'/v1/client/magic_link/redeem?'.$query;
    }

    private function mintJti(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
