<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Environment;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 12 system permissions and the two default roles
 * (`org:admin`, `org:member`) into a freshly-minted environment.
 *
 * The mapping mirrors PLAN §4.5: `*:read` for `org:member`, every system
 * key for `org:admin`. Operators can override role-permission links from
 * the Dashboard once AU-5's BAPI ships.
 *
 * Idempotent: re-runs leave exactly one row per `(environment_id, key)`.
 */
final class RoleSeeder
{
    /**
     * 12 system permission keys, in the order PLAN §4.5 lists them.
     *
     * @var list<array{key: string, name: string}>
     */
    public const SYSTEM_PERMISSIONS = [
        ['key' => 'org:profile:read', 'name' => 'Read organization profile'],
        ['key' => 'org:profile:manage', 'name' => 'Manage organization profile'],
        ['key' => 'org:profile:delete', 'name' => 'Delete organization'],
        ['key' => 'org:memberships:read', 'name' => 'Read organization memberships'],
        ['key' => 'org:memberships:manage', 'name' => 'Manage organization memberships'],
        ['key' => 'org:domains:read', 'name' => 'Read organization domains'],
        ['key' => 'org:domains:manage', 'name' => 'Manage organization domains'],
        ['key' => 'org:billing:read', 'name' => 'Read organization billing'],
        ['key' => 'org:billing:manage', 'name' => 'Manage organization billing'],
        ['key' => 'org:sso:read', 'name' => 'Read organization SSO settings'],
        ['key' => 'org:sso:manage', 'name' => 'Manage organization SSO settings'],
        ['key' => 'org:provisioning:read', 'name' => 'Read organization provisioning'],
        ['key' => 'org:provisioning:manage', 'name' => 'Manage organization provisioning'],
    ];

    public const ROLE_ADMIN = 'org:admin';

    public const ROLE_MEMBER = 'org:member';

    /**
     * Seed the env. Returns the seeded admin/member roles for callers that
     * want to immediately link a membership to one of them.
     *
     * @return array{admin: Role, member: Role}
     */
    public function seed(Environment $environment): array
    {
        return DB::transaction(function () use ($environment): array {
            $perms = [];
            foreach (self::SYSTEM_PERMISSIONS as $row) {
                $perm = Permission::query()
                    ->withoutGlobalScopes()
                    ->where('environment_id', $environment->id)
                    ->where('key', $row['key'])
                    ->first();

                if ($perm === null) {
                    $perm = Permission::create([
                        'environment_id' => $environment->id,
                        'key' => $row['key'],
                        'name' => $row['name'],
                        'is_system' => true,
                    ]);
                }
                $perms[$row['key']] = $perm;
            }

            $admin = $this->ensureRole($environment, self::ROLE_ADMIN, 'Organization admin', [
                'is_creator_eligible' => true,
                'is_default' => false,
                'is_system' => true,
            ]);
            $member = $this->ensureRole($environment, self::ROLE_MEMBER, 'Organization member', [
                'is_creator_eligible' => false,
                'is_default' => true,
                'is_system' => true,
            ]);

            $admin->permissions()->sync(array_values(array_map(fn (Permission $p): string => $p->id, $perms)));

            $readOnlyPermIds = array_values(array_map(
                fn (Permission $p): string => $p->id,
                array_filter($perms, fn (string $key): bool => str_ends_with($key, ':read'), ARRAY_FILTER_USE_KEY),
            ));
            $member->permissions()->sync($readOnlyPermIds);

            return ['admin' => $admin, 'member' => $member];
        });
    }

    private function ensureRole(Environment $environment, string $key, string $name, array $flags): Role
    {
        $role = Role::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $environment->id)
            ->where('key', $key)
            ->first();

        if ($role === null) {
            return Role::create([
                'environment_id' => $environment->id,
                'key' => $key,
                'name' => $name,
                ...$flags,
            ]);
        }

        return $role;
    }
}
