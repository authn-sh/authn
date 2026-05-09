<?php

declare(strict_types=1);

namespace App\Services\MagicLink;

use App\Models\Environment;
use App\Models\SignInAttempt;
use App\Models\SigningKey;
use App\Models\SignUpAttempt;
use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\DB;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;

/**
 * Click-time companion to MagicLinkIssuer. Verifies the JWT against the
 * env's signing keys, ensures `aud=magic_link`, checks expiry, and
 * atomically:
 *
 *   - marks the matching VerificationCode consumed (replay protection),
 *   - flips the parent Verification.status to verified,
 *   - returns the parent attempt (SignInAttempt | SignUpAttempt) so the
 *     controller can render the right redirect.
 *
 * Re-clicking the same link returns `consumed`.
 */
final class MagicLinkVerifier
{
    /**
     * @return array{
     *   verification: ?Verification,
     *   attempt: SignInAttempt|SignUpAttempt|null,
     *   error: ?string,
     * }
     */
    public function verify(string $jwt, Environment $environment): array
    {
        $tokenHash = hash('sha256', $jwt);

        $code = VerificationCode::query()
            ->where('code_hash', $tokenHash)
            ->where('purpose', VerificationCode::PURPOSE_MAGIC_LINK)
            ->whereHas('verification', fn ($q) => $q->where('environment_id', $environment->id))
            ->first();
        if ($code === null) {
            return $this->fail('invalid');
        }
        if ($code->consumed_at !== null) {
            return $this->fail('consumed');
        }
        if ($code->expires_at->isPast()) {
            return $this->fail('expired');
        }

        if (! $this->signatureMatches($jwt, $environment)) {
            return $this->fail('invalid');
        }

        $verification = $code->verification;
        if ($verification === null) {
            return $this->fail('invalid');
        }
        if ($verification->status !== Verification::STATUS_UNVERIFIED) {
            return $this->fail('consumed');
        }

        $attempt = $this->resolveAttempt($verification);

        DB::transaction(function () use ($code, $verification): void {
            $code->forceFill(['consumed_at' => now()])->save();
            $verification->forceFill([
                'status' => Verification::STATUS_VERIFIED,
                'verified_at' => now(),
            ])->save();
        });

        return [
            'verification' => $verification->fresh(),
            'attempt' => $attempt,
            'error' => null,
        ];
    }

    /**
     * @return array{verification: null, attempt: null, error: string}
     */
    private function fail(string $error): array
    {
        return ['verification' => null, 'attempt' => null, 'error' => $error];
    }

    private function signatureMatches(string $jwt, Environment $environment): bool
    {
        try {
            $config = Configuration::forSymmetricSigner(
                new Sha256,
                InMemory::plainText(str_repeat('x', 64)), // unused, parser only
            );
            $token = $config->parser()->parse($jwt);
            if (! $token instanceof Plain) {
                return false;
            }
            $kid = $token->headers()->get('kid');
            if (! is_string($kid) || $kid === '') {
                return false;
            }
        } catch (\Throwable) {
            return false;
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
            return false;
        }

        try {
            $publicPem = $this->extractPublicPem($signingKey);
            $verifyConfig = Configuration::forAsymmetricSigner(
                new Sha256,
                InMemory::plainText('private-not-needed-for-verification'),
                InMemory::plainText($publicPem),
            );
            $verifyConfig->validator()->assert(
                $token,
                new SignedWith($verifyConfig->signer(), $verifyConfig->verificationKey()),
                new StrictValidAt(SystemClock::fromSystemTimezone()),
                new PermittedFor('magic_link'),
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function extractPublicPem(SigningKey $signingKey): string
    {
        $resource = openssl_pkey_get_private($signingKey->privatePem());
        $details = openssl_pkey_get_details($resource);

        return $details['key'];
    }

    private function resolveAttempt(Verification $verification): SignInAttempt|SignUpAttempt|null
    {
        $type = $verification->verifiable_type;
        $id = $verification->verifiable_id;
        if ($type === (new SignInAttempt)->getMorphClass()) {
            return SignInAttempt::query()->withoutGlobalScopes()->where('id', $id)->first();
        }
        if ($type === (new SignUpAttempt)->getMorphClass()) {
            return SignUpAttempt::query()->withoutGlobalScopes()->where('id', $id)->first();
        }

        return null;
    }
}
