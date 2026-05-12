<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\JwtTemplate;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PhoneNumber;
use App\Models\Session;
use App\Models\SigningKey;
use App\Models\User;
use App\Support\Url;
use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HmacSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use RuntimeException;

/**
 * v0.7 JWT template rendering engine. Walks the `claims` JSON of a
 * `JwtTemplate`, substitutes `{{path}}`-style Liquid placeholders against
 * a snapshot of the User / Session / Organization at mint time, and signs
 * the resulting payload with either the env's active SigningKey (default
 * RS256) or the template's `custom_signing_key` escape hatch.
 *
 * The placeholder grammar is intentionally narrow:
 *
 *   - `{{user.id}}`, `{{user.first_name}}`, `{{user.public_metadata.foo}}`
 *   - `{{session.id}}`, `{{session.status}}`
 *   - `{{org.id}}`, `{{org.slug}}`, `{{org.role}}`
 *   - `{{env.id}}`
 *
 * Whitespace inside the braces is allowed: `{{ user.id }}`. Missing
 * paths render as an empty string in string-typed claim values; in
 * scalar-typed root claim values (number / boolean) the literal "" is
 * the safe fallback per JWT shape stability.
 *
 * `user.private_metadata` is rejected by design — operators have to opt
 * in by toggling a future per-template flag (out of scope for v0.7;
 * tracked in PLAN §10.5 follow-up).
 *
 * Hand-rolled rather than depending on `liquid/liquid` to avoid the
 * MIT/LGPL license discussion and to keep the renderer's attack surface
 * small (~ 100 LOC of substitution).
 */
final class JwtTemplateRenderer
{
    public const PRIVATE_METADATA_REJECTED = 'jwt_template_private_metadata_rejected';

    public function render(
        JwtTemplate $template,
        User $user,
        Session $session,
        ?Organization $organization = null,
    ): string {
        $snapshot = $this->snapshot($user, $session, $organization);
        $rendered = $this->walk($template->claims, $snapshot);
        $config = $this->configFor($template);
        $now = now();
        $lifetime = $template->lifetime > 0 ? $template->lifetime : 60;
        $expiresAt = $now->copy()->addSeconds($lifetime);

        $builder = $config->builder()
            ->issuedBy(Url::fapi($user->environment, ''))
            ->relatedTo($user->id)
            ->identifiedBy($this->mintJti())
            ->issuedAt($now->toDateTimeImmutable())
            ->canOnlyBeUsedAfter($now->copy()->subSeconds($template->allowed_clock_skew)->toDateTimeImmutable())
            ->expiresAt($expiresAt->toDateTimeImmutable());

        $kid = $this->kidFor($template, $user->environment);
        if ($kid !== null) {
            $builder = $builder->withHeader('kid', $kid);
        }

        foreach ($rendered as $name => $value) {
            // Reserved JWT claim names that lcobucci/jwt manages on the
            // builder go through the typed setters; everything else rides
            // as a free-form custom claim.
            $builder = match ($name) {
                'sub' => $builder->relatedTo(is_string($value) ? $value : (string) $value),
                'aud' => $builder->permittedFor(...$this->audArray($value)),
                'iss' => $builder->issuedBy(is_string($value) ? $value : (string) $value),
                default => $builder->withClaim($name, $value),
            };
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user, Session $session, ?Organization $organization): array
    {
        $primaryEmail = EmailAddress::query()
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->value('email_address');

        $primaryPhone = PhoneNumber::query()
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->value('phone_number');

        $userPublic = is_array($user->public_metadata ?? null) ? $user->public_metadata : [];
        $userUnsafe = is_array($user->unsafe_metadata ?? null) ? $user->unsafe_metadata : [];

        $orgSnapshot = null;
        if ($organization !== null) {
            $membership = OrganizationMembership::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->with('role')
                ->first();
            $orgSnapshot = [
                'id' => $organization->id,
                'slug' => $organization->slug,
                'name' => $organization->name,
                'role' => $membership?->role?->key,
                'public_metadata' => is_array($organization->public_metadata ?? null) ? $organization->public_metadata : [],
            ];
        }

        return [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $primaryEmail,
                'primary_email' => $primaryEmail,
                'phone' => $primaryPhone,
                'primary_phone' => $primaryPhone,
                'public_metadata' => $userPublic,
                'unsafe_metadata' => $userUnsafe,
            ],
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
            ],
            'org' => $orgSnapshot,
            'organization' => $orgSnapshot,
            'env' => [
                'id' => $user->environment_id,
            ],
        ];
    }

    /**
     * @param  mixed  $value
     * @param  array<string, mixed>  $snapshot
     * @return mixed
     */
    private function walk($value, array $snapshot)
    {
        if (is_string($value)) {
            return $this->renderString($value, $snapshot);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->walk($v, $snapshot);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function renderString(string $template, array $snapshot): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($snapshot): string {
                $path = (string) $m[1];
                if (str_starts_with($path, 'user.private_metadata')) {
                    throw new InvalidArgumentException(self::PRIVATE_METADATA_REJECTED);
                }
                $value = $this->resolvePath($snapshot, $path);

                if ($value === null) {
                    return '';
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }

                return (string) json_encode($value);
            },
            $template,
        );
    }

    /**
     * @param  array<string, mixed>  $tree
     */
    private function resolvePath(array $tree, string $path): mixed
    {
        $segments = explode('.', $path);
        $node = $tree;
        foreach ($segments as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private function configFor(JwtTemplate $template): Configuration
    {
        $signer = $this->signer($template);

        if ($template->custom_signing_key !== null && $template->custom_signing_key !== '') {
            return Configuration::forAsymmetricSigner(
                $signer,
                InMemory::plainText($template->custom_signing_key),
                InMemory::plainText('public-not-needed-for-signing'),
            );
        }

        // Default: env's active session-signing key. RS256 only — operators
        // that need ES256 / HS256 must supply a custom_signing_key.
        $signingKey = $template->environment->signingKeys()
            ->where('status', SigningKey::STATUS_ACTIVE)
            ->latest('activated_at')
            ->first();
        if ($signingKey === null) {
            throw new RuntimeException('No active SigningKey for env '.$template->environment_id);
        }

        if ($template->signing_algorithm !== JwtTemplate::ALG_RS256) {
            throw new RuntimeException(
                "Template `{$template->name}` requests {$template->signing_algorithm} but no custom_signing_key is set; ".
                'either set a custom_signing_key or switch the algorithm to RS256.'
            );
        }

        return Configuration::forAsymmetricSigner(
            new RsaSha256,
            InMemory::plainText($signingKey->privatePem()),
            InMemory::plainText('public-not-needed-for-signing'),
        );
    }

    private function signer(JwtTemplate $template): Signer
    {
        return match ($template->signing_algorithm) {
            JwtTemplate::ALG_ES256 => new EcdsaSha256,
            JwtTemplate::ALG_HS256 => new HmacSha256,
            default => new RsaSha256,
        };
    }

    private function kidFor(JwtTemplate $template, Environment $env): ?string
    {
        if ($template->custom_signing_key !== null && $template->custom_signing_key !== '') {
            // Custom key: there's no SigningKey row that corresponds, so we
            // surface the template id as the kid so consumers can correlate
            // back. JWKS / verification paths know that `jtmpl_*` kids
            // resolve via the JwtTemplate.custom_signing_key public half
            // rather than the env's SigningKey table.
            return $template->id;
        }
        $signingKey = $env->signingKeys()
            ->where('status', SigningKey::STATUS_ACTIVE)
            ->latest('activated_at')
            ->first();

        return $signingKey?->id;
    }

    /**
     * @param  mixed  $aud
     * @return list<string>
     */
    private function audArray($aud): array
    {
        if (is_array($aud)) {
            return array_map(static fn ($v) => is_string($v) ? $v : (string) $v, array_values($aud));
        }

        return [is_string($aud) ? $aud : (string) $aud];
    }

    private function mintJti(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
