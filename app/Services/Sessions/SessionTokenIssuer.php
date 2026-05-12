<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Auth\Jwt\JwtTemplateNotFound;
use App\Auth\Jwt\JwtTemplateRenderer;
use App\Models\EnterpriseAccount;
use App\Models\Environment;
use App\Models\JwtTemplate;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Passkey;
use App\Models\PhoneNumber;
use App\Models\Session;
use App\Models\SignInAttempt;
use App\Models\SigningKey;
use App\Models\TotpSecret;
use App\Models\Verification;
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

        // v0.7: a non-`default`, non-`flat` template name is a user-defined
        // JwtTemplate. Look it up by (environment_id, name) and delegate the
        // render to JwtTemplateRenderer. The "default" + "flat" shapes
        // continue to ride the legacy session-token path below so v0.1+
        // consumers don't see any shape change.
        if ($template !== 'default' && $template !== 'flat') {
            $row = JwtTemplate::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $environment->id)
                ->where('name', $template)
                ->first();
            if ($row === null) {
                throw new JwtTemplateNotFound($template);
            }
            $jwt = (new JwtTemplateRenderer)->render(
                $row,
                $session->user,
                $session,
                $this->orgForSession($session),
            );
            // Stamp last_used_at so AU-4's BAPI DELETE can enforce the
            // grace window before this template name vanishes from a
            // long-lived verifier cache.
            $row->forceFill(['last_used_at' => now()])->save();
            $expiresAt = now()->addSeconds($row->lifetime > 0 ? $row->lifetime : 60)->getTimestamp();

            return [
                'jwt' => $jwt,
                'expires_at' => $expiresAt,
                'kid' => $row->custom_signing_key !== null && $row->custom_signing_key !== ''
                    ? $row->id
                    : ($environment->signingKeys()
                        ->where('status', SigningKey::STATUS_ACTIVE)
                        ->latest('activated_at')
                        ->value('id') ?? ''),
            ];
        }

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

        $orgClaim = $this->orgClaim($session);
        if ($orgClaim !== null) {
            $builder = $builder->withClaim('org', $orgClaim);
        }

        $builder = $builder->withClaim('pnv', $this->phoneNumberVerified($session));
        $builder = $builder->withClaim('pkv', $this->passkeyVerified($session));
        $builder = $builder->withClaim('pkc', $this->passkeyCount($session));

        $enterprise = $this->enterpriseClaims($session);
        if ($enterprise !== null) {
            $builder = $builder->withClaim('entcon', $enterprise['entcon']);
            $builder = $builder->withClaim('entacc', $enterprise['entacc']);
        }

        $dsf = $this->defaultSecondFactor($session);
        if ($dsf !== null) {
            $builder = $builder->withClaim('dsf', $dsf);
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
        // v0.7: the named-JwtTemplate path is resolved upstream in `mint()`.
        // What lands here is `default` (env-controlled shape) or `flat`
        // (caller force-overrides via Session::getToken('flat')).
        if ($template === 'flat') {
            return 'flat';
        }

        $appearance = $environment->appearance ?? [];
        $sessions = is_array($appearance) ? ($appearance['sessions'] ?? []) : [];
        $shape = is_array($sessions) ? ($sessions['session_token_template'] ?? 'default') : 'default';

        return $shape === 'flat' ? 'flat' : 'default';
    }

    /**
     * Resolves the Organization to pin to the JWT-template render snapshot,
     * if any. Picks `last_active_organization_id` when set; null otherwise.
     */
    private function orgForSession(Session $session): ?Organization
    {
        $orgId = $session->last_active_organization_id;
        if (! is_string($orgId) || $orgId === '') {
            return null;
        }

        return Organization::query()->withoutGlobalScopes()->find($orgId);
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
     * Compact `org` claim populated from the active membership when the
     * Session has `last_active_organization_id` set. Returns null when the
     * session has no active org or when the row has been deleted between
     * the touch and the mint.
     *
     * @return array{id: string, slg: string, rol: string, per: list<string>}|null
     */
    private function orgClaim(Session $session): ?array
    {
        $orgId = $session->last_active_organization_id;
        if (! is_string($orgId) || $orgId === '') {
            return null;
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $session->user_id)
            ->with(['organization', 'role.permissions'])
            ->first();
        if ($membership === null || $membership->organization === null || $membership->role === null) {
            return null;
        }

        return [
            'id' => $membership->organization->id,
            'slg' => $membership->organization->slug,
            'rol' => $membership->role->key,
            'per' => $membership->role->permissions->pluck('key')->unique()->values()->all(),
        ];
    }

    private function phoneNumberVerified(Session $session): bool
    {
        return PhoneNumber::query()
            ->withoutGlobalScopes()
            ->where('user_id', $session->user_id)
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * Whether the SignInAttempt that produced this Session was verified via
     * the `passkey` first-factor strategy. SDK consumers (sdk-php SP-3 /
     * sdk-php-laravel SPL-1) use this to gate "require a passkey for this
     * resource" middleware without an extra /v1/me round-trip.
     */
    private function passkeyVerified(Session $session): bool
    {
        $attempt = SignInAttempt::query()
            ->withoutGlobalScopes()
            ->where('created_session_id', $session->id)
            ->first();
        if ($attempt === null) {
            return false;
        }

        return Verification::query()
            ->withoutGlobalScopes()
            ->where('verifiable_type', $attempt->getMorphClass())
            ->where('verifiable_id', $attempt->id)
            ->where('strategy', Verification::STRATEGY_PASSKEY)
            ->where('status', Verification::STATUS_VERIFIED)
            ->exists();
    }

    /**
     * Compact enterprise-SSO state for downstream SDK consumers (sdk-php
     * SP-3, sdk-node JS-8). Only emits when the SignInAttempt that
     * produced this session was verified via the `enterprise_sso` /
     * `saml` strategy AND the matching `EnterpriseAccount` row is still
     * live. Returns null otherwise so the claims are absent from the JWT.
     *
     * @return array{entcon: string, entacc: string}|null
     */
    private function enterpriseClaims(Session $session): ?array
    {
        $attempt = SignInAttempt::query()
            ->withoutGlobalScopes()
            ->where('created_session_id', $session->id)
            ->first();
        if ($attempt === null) {
            return null;
        }

        $verification = Verification::query()
            ->withoutGlobalScopes()
            ->where('verifiable_type', $attempt->getMorphClass())
            ->where('verifiable_id', $attempt->id)
            ->whereIn('strategy', [Verification::STRATEGY_ENTERPRISE_SSO, Verification::STRATEGY_SAML])
            ->where('status', Verification::STATUS_VERIFIED)
            ->latest('id')
            ->first();
        if ($verification === null) {
            return null;
        }

        $account = EnterpriseAccount::query()
            ->withoutGlobalScopes()
            ->where('user_id', $session->user_id)
            ->latest('last_signed_in_at')
            ->first();
        if ($account === null) {
            return null;
        }

        return [
            'entcon' => $account->enterprise_connection_id,
            'entacc' => $account->id,
        ];
    }

    /**
     * Snapshot of the user's verified passkey count at JWT mint time.
     */
    private function passkeyCount(Session $session): int
    {
        return Passkey::query()
            ->where('user_id', $session->user_id)
            ->whereNotNull('verified_at')
            ->count();
    }

    /**
     * Resolves the user's preferred second-factor strategy. Phone wins when
     * a verified row is flagged `default_second_factor`; otherwise any
     * verified TOTP enrolment maps to `"totp"`. Returns null when neither
     * holds, so `dsf` is omitted entirely from the JWT (consumers default
     * to "no preference").
     */
    private function defaultSecondFactor(Session $session): ?string
    {
        $hasDefaultPhone = PhoneNumber::query()
            ->withoutGlobalScopes()
            ->where('user_id', $session->user_id)
            ->whereNotNull('verified_at')
            ->where('default_second_factor', true)
            ->exists();
        if ($hasDefaultPhone) {
            return 'phone_code';
        }

        $hasTotp = TotpSecret::query()
            ->withoutGlobalScopes()
            ->where('user_id', $session->user_id)
            ->whereNotNull('verified_at')
            ->exists();

        return $hasTotp ? 'totp' : null;
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
