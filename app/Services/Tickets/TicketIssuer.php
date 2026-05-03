<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\Environment;
use App\Models\Invitation;
use App\Models\SigningKey;
use App\Support\Url;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/**
 * Mints `__authn_ticket` JWTs for invitation / sign-in-token / actor-token
 * URL flows (PLAN §9.7).
 *
 * Tokens carry:
 *   aud = "ticket"
 *   iss = the env's FAPI URL
 *   sub = the redemption subject (email for invitations, user_id for
 *         sign-in tokens)
 *   sid = the source resource id (`inv_…`, `sit_…`, `act_…`)
 *   purpose = `invitation | organization_invitation | sign_in_token |
 *             actor_token | waitlist_invite`
 *   metadata = public_metadata copied from the source
 *   redirect_url = post-redemption landing
 *
 * Single-use semantics live in the verifier (jti tracked in the cache for
 * the lifetime of the token).
 */
final class TicketIssuer
{
    /**
     * @param  array<string, mixed>  $claims  extras like sub, sid, purpose, metadata, redirect_url.
     */
    public function issue(Environment $environment, int $ttlSeconds, array $claims): string
    {
        $signingKey = $environment->signingKeys()
            ->where('status', SigningKey::STATUS_ACTIVE)
            ->latest('activated_at')
            ->firstOrFail();

        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($signingKey->privatePem()),
            InMemory::plainText('public-not-needed-for-signing'),
        );

        $now = now();
        $expiresAt = $now->copy()->addSeconds($ttlSeconds);

        $builder = $config->builder()
            ->withHeader('kid', $signingKey->id)
            ->issuedBy(Url::fapi($environment, ''))
            ->permittedFor('ticket')
            ->identifiedBy($this->mintJti())
            ->issuedAt($now->toDateTimeImmutable())
            ->canOnlyBeUsedAfter($now->toDateTimeImmutable())
            ->expiresAt($expiresAt->toDateTimeImmutable());

        if (isset($claims['sub']) && is_string($claims['sub'])) {
            $builder = $builder->relatedTo($claims['sub']);
        }
        foreach (['sid', 'purpose', 'metadata', 'redirect_url'] as $name) {
            if (array_key_exists($name, $claims)) {
                $builder = $builder->withClaim($name, $claims[$name]);
            }
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }

    /**
     * Mint a JWT for an invitation. The redemption flow is in
     * `App\Auth\SignUp\TicketRedeemer`; the URL the operator emails out is
     * the env's FAPI host + `__authn_ticket=<jwt>` (PLAN §9.7).
     */
    public function issueForInvitation(Invitation $invitation, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds
            ?? ($invitation->expires_at !== null
                ? max(60, $invitation->expires_at->getTimestamp() - now()->getTimestamp())
                : 30 * 24 * 60 * 60);

        return $this->issue($invitation->environment, $ttl, [
            'sub' => strtolower($invitation->email_address),
            'sid' => $invitation->id,
            'purpose' => 'invitation',
            'metadata' => is_array($invitation->public_metadata) ? $invitation->public_metadata : [],
            'redirect_url' => $invitation->redirect_url,
        ]);
    }

    private function mintJti(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
