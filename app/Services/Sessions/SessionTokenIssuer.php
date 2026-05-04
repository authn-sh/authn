<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Models\Environment;
use App\Models\Session;
use App\Models\SigningKey;
use App\Support\Url;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use RuntimeException;

/**
 * Mints the short-lived JWT that the SDK stuffs into the `__session` cookie.
 *
 * The shape mirrors PLAN §10.2's v2 token. v0.1 omits the `o.*` (org) and
 * `act` (impersonation) claim families — those land in v0.2 and v0.3
 * respectively. JWT templates (post-v0.7) will eventually let the operator
 * pick a custom claim shape; for v0.1 the only template name accepted is
 * `default`, and any other name returns `template_not_found`.
 *
 * The flat-claim alternative (PLAN §10.3) is selected when the env's
 * `sessions.session_token_template` setting is set to `flat`.
 */
final class SessionTokenIssuer
{
    /** Default lifetime in seconds when the env doesn't override. */
    public const DEFAULT_LIFETIME_SECONDS = 60;

    /**
     * @return array{jwt: string, expires_at: int, kid: string}
     *
     * @throws InvalidArgumentException on `template_not_found`.
     * @throws RuntimeException when no active SigningKey exists for the env.
     */
    public function mint(Session $session, ?string $template = null, ?Request $request = null, ?int $lifetimeOverride = null): array
    {
        $environment = $session->environment;
        $template ??= 'default';
        $shape = $this->resolveTemplateShape($environment, $template);

        $signingKey = $environment->signingKeys()
            ->where('status', SigningKey::STATUS_ACTIVE)
            ->latest('activated_at')
            ->firstOrFail();

        $config = $this->configForKey($signingKey);
        $now = now();
        $lifetime = $lifetimeOverride ?? $this->lifetimeFor($environment);
        $expiresAt = $now->copy()->addSeconds($lifetime);
        $azp = $this->resolveAzp($request);

        $builder = $config->builder()
            ->withHeader('kid', $signingKey->id)
            ->issuedBy($this->issuer($environment))
            ->relatedTo($session->user_id)
            ->identifiedBy($this->mintJti())
            ->issuedAt($now->toDateTimeImmutable())
            ->canOnlyBeUsedAfter($now->toDateTimeImmutable())
            ->expiresAt($expiresAt->toDateTimeImmutable())
            ->withClaim('sid', $session->id)
            ->withClaim('v', 2)
            ->withClaim('fva', $this->factorVerificationAge($session))
            ->withClaim('sts', $session->status === Session::STATUS_PENDING ? 'pending' : 'active');

        if ($session->was_test) {
            $builder = $builder->withClaim('was_test', true);
        }

        if ($azp !== null) {
            $builder = $builder->withClaim('azp', $azp);
        }

        if ($shape === 'flat') {
            // Flat-claim mode (PLAN §10.3): consumers like Hasura / Postgres
            // RLS read claims directly without unpacking a nested object.
            // v0.1 has no org / actor data to surface yet — these go in
            // when v0.2 (orgs) and v0.3 (impersonation) light up.
        }

        $token = $builder->getToken($config->signer(), $config->signingKey());

        return [
            'jwt' => $token->toString(),
            'expires_at' => $expiresAt->getTimestamp(),
            'kid' => $signingKey->id,
        ];
    }

    private function lifetimeFor(Environment $environment): int
    {
        $blob = is_array($environment->localization) ? [] : []; // placeholder accessor
        // InstanceSetting wiring lands in AU-13; until then, honour
        // `Environment.appearance.sessions.lifetime_seconds` if a test
        // wants to override, otherwise the default.
        $appearance = $environment->appearance ?? [];
        $sessions = is_array($appearance) ? ($appearance['sessions'] ?? []) : [];
        $custom = is_array($sessions) ? ($sessions['lifetime_seconds'] ?? null) : null;

        return is_int($custom) && $custom > 0 ? $custom : self::DEFAULT_LIFETIME_SECONDS;
    }

    private function resolveTemplateShape(Environment $environment, string $template): string
    {
        if ($template !== 'default') {
            // Custom JWT templates land in v0.7; reject everything else for now.
            throw new InvalidArgumentException("template_not_found:{$template}");
        }

        $appearance = $environment->appearance ?? [];
        $sessions = is_array($appearance) ? ($appearance['sessions'] ?? []) : [];
        $shape = is_array($sessions) ? ($sessions['session_token_template'] ?? 'default') : 'default';

        return $shape === 'flat' ? 'flat' : 'default';
    }

    private function configForKey(SigningKey $signingKey): Configuration
    {
        return Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($signingKey->privatePem()),
            InMemory::plainText('public-not-needed-for-signing'),
        );
    }

    private function issuer(Environment $environment): string
    {
        return Url::fapi($environment, '');
    }

    private function mintJti(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    private function resolveAzp(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }
        $origin = $request->headers->get('Origin');

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    /**
     * `[seconds_since_first_factor, seconds_since_second_factor_or_-1]` per PLAN §10.2.
     * v0.1 has no MFA, so the second factor is always -1 and the first factor
     * is approximated as the time since the session was last touched.
     *
     * @return list<int>
     */
    private function factorVerificationAge(Session $session): array
    {
        $first = $session->last_active_at !== null
            ? max(0, now()->getTimestamp() - $session->last_active_at->getTimestamp())
            : 0;

        return [$first, -1];
    }
}
