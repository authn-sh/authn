<?php

declare(strict_types=1);

namespace App\Auth\EnterpriseSso;

use App\Models\EmailAddress;
use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\Environment;
use App\Models\OrganizationDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves enterprise SSO domain → connection routing, and find-or-creates
 * the `User` + `EnterpriseAccount` rows from an inbound verified identity
 * (SAML assertion or OIDC id_token).
 *
 * Domain matching (per PLAN §11.5):
 *  1. Match the identifier's domain against verified `OrganizationDomain.domain`.
 *  2. Walk the org's `EnterpriseConnection` rows where the domain appears in
 *     `domains[]` and `enabled = true`.
 *  3. Fall back to instance-wide connections (`organization_id IS NULL`) when
 *     no org-scoped match exists.
 */
final class EnterpriseConnectionService
{
    /**
     * Find the `EnterpriseConnection` an identifier (typically an email)
     * should route to, or null when nothing matches. Honours the v0.3
     * strict-semantic contract — disabled connections are skipped at
     * resolution time so new sign-ins drop through; existing linked
     * accounts still reach the connection via direct lookup.
     */
    public function findByIdentifierDomain(Environment $env, string $identifier): ?EnterpriseConnection
    {
        $domain = $this->extractDomain($identifier);
        if ($domain === null) {
            return null;
        }

        $orgMatch = OrganizationDomain::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('name', $domain)
            ->where('verified', true)
            ->first();

        if ($orgMatch !== null) {
            $orgScoped = EnterpriseConnection::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->where('organization_id', $orgMatch->organization_id)
                ->where('enabled', true)
                ->get()
                ->first(fn (EnterpriseConnection $conn) => $this->connectionCoversDomain($conn, $domain));
            if ($orgScoped !== null) {
                return $orgScoped;
            }
        }

        return EnterpriseConnection::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->whereNull('organization_id')
            ->where('enabled', true)
            ->get()
            ->first(fn (EnterpriseConnection $conn) => $this->connectionCoversDomain($conn, $domain));
    }

    /**
     * Find-or-create the `User` + `EnterpriseAccount` pair for the
     * inbound identity. `$identity` carries the IdP-side subject id,
     * email, name, and the raw attribute bag the connection's
     * `attribute_mapping` may want to merge onto `User.public_metadata`.
     *
     * @param  array{
     *     provider_user_id:string,
     *     email_address:?string,
     *     first_name:?string,
     *     last_name:?string,
     *     id_token:?string,
     *     raw_attributes:array<string,mixed>,
     * }  $identity
     * @return array{user: User, account: EnterpriseAccount, was_created: bool}
     */
    public function provisionUserFromIdentity(EnterpriseConnection $conn, array $identity): array
    {
        return DB::transaction(function () use ($conn, $identity): array {
            $existing = EnterpriseAccount::query()
                ->withoutGlobalScopes()
                ->where('enterprise_connection_id', $conn->id)
                ->where('provider_user_id', $identity['provider_user_id'])
                ->first();

            if ($existing !== null) {
                $existing->last_signed_in_at = now();
                $existing->id_token = $identity['id_token'];
                $existing->save();
                $user = User::query()->withoutGlobalScopes()->where('id', $existing->user_id)->firstOrFail();

                return ['user' => $user, 'account' => $existing->fresh(), 'was_created' => false];
            }

            $user = $this->findUserByEmail($conn->environment_id, $identity['email_address']);
            $created = false;
            if ($user === null) {
                $user = User::query()->withoutGlobalScopes()->create([
                    'environment_id' => $conn->environment_id,
                    'first_name' => $identity['first_name'],
                    'last_name' => $identity['last_name'],
                ]);
                $created = true;
                if (is_string($identity['email_address']) && $identity['email_address'] !== '') {
                    $email = EmailAddress::query()->withoutGlobalScopes()->create([
                        'environment_id' => $conn->environment_id,
                        'user_id' => $user->id,
                        'email_address' => strtolower($identity['email_address']),
                        'verified_at' => now(),
                        'is_primary' => true,
                    ]);
                    $user->forceFill(['primary_email_address_id' => $email->id])->save();
                }
            }

            $account = EnterpriseAccount::query()->withoutGlobalScopes()->create([
                'environment_id' => $conn->environment_id,
                'user_id' => $user->id,
                'enterprise_connection_id' => $conn->id,
                'provider_user_id' => $identity['provider_user_id'],
                'email_address' => $identity['email_address'],
                'verified' => true,
                'public_metadata' => $this->mapAttributes($conn, $identity['raw_attributes']),
                'id_token' => $identity['id_token'],
                'linked_at' => now(),
                'last_signed_in_at' => now(),
            ]);

            return ['user' => $user->fresh(), 'account' => $account, 'was_created' => $created];
        });
    }

    /**
     * Apply the connection's `attribute_mapping` (`{idp_attr: internal_path}`)
     * to the raw attribute bag, returning the subset to persist on
     * `EnterpriseAccount.public_metadata`. Unmapped attributes are
     * dropped — operators opt them in through `attribute_mapping`.
     *
     * @param  array<string,mixed>  $rawAttributes
     * @return array<string,mixed>
     */
    private function mapAttributes(EnterpriseConnection $conn, array $rawAttributes): array
    {
        $mapping = is_array($conn->attribute_mapping) ? $conn->attribute_mapping : [];
        $mapped = [];
        foreach ($mapping as $source => $target) {
            if (! is_string($source) || ! is_string($target)) {
                continue;
            }
            if (array_key_exists($source, $rawAttributes)) {
                $mapped[$target] = $rawAttributes[$source];
            }
        }

        return $mapped;
    }

    private function findUserByEmail(string $environmentId, ?string $email): ?User
    {
        if (! is_string($email) || $email === '') {
            return null;
        }
        $row = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $environmentId)
            ->where('email_address', strtolower($email))
            ->first();
        if ($row === null) {
            return null;
        }

        return User::query()->withoutGlobalScopes()->where('id', $row->user_id)->first();
    }

    private function extractDomain(string $identifier): ?string
    {
        $at = strrpos($identifier, '@');
        if ($at === false) {
            return null;
        }
        $domain = strtolower(trim(substr($identifier, $at + 1)));

        return $domain === '' ? null : $domain;
    }

    private function connectionCoversDomain(EnterpriseConnection $conn, string $domain): bool
    {
        $domains = is_array($conn->domains) ? $conn->domains : [];
        foreach ($domains as $d) {
            if (is_string($d) && strtolower($d) === $domain) {
                return true;
            }
        }

        return false;
    }
}
