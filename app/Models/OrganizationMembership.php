<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $user_id
 * @property ?string $role legacy v0.1 string slug; replaced by role_id in v0.2 (AU-2 backfills + drops)
 * @property ?string $role_id
 * @property array $public_metadata
 * @property array $private_metadata
 */
class OrganizationMembership extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    /**
     * Roles seeded inside the `_admin` system project for operator access.
     * The full role/permission machinery lands in v0.2 — these constants
     * just give the bootstrap something to label memberships with.
     */
    public const ROLE_WORKSPACE_OWNER = 'org:workspace_owner';

    public const ROLE_WORKSPACE_ADMIN = 'org:workspace_admin';

    public const ROLE_WORKSPACE_DEVELOPER = 'org:workspace_developer';

    public const ROLE_WORKSPACE_BILLING = 'org:workspace_billing';

    /**
     * Default org-member roles that v0.2 will flesh out into Role rows.
     */
    public const ROLE_ADMIN = 'org:admin';

    public const ROLE_MEMBER = 'org:member';

    protected string $idPrefix = 'orgmem_';

    protected $table = 'organization_memberships';

    protected $fillable = [
        'environment_id',
        'organization_id',
        'user_id',
        'role',
        'role_id',
        'public_metadata',
        'private_metadata',
    ];

    protected $hidden = [
        'private_metadata',
    ];

    protected function casts(): array
    {
        return [
            'public_metadata' => 'array',
            'private_metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roleRef(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Permission keys granted by this membership's role. Returns an empty
     * list when the membership predates the v0.2 role table (legacy `role`
     * string only); AU-2's backfill populates `role_id` for those rows.
     */
    public function permissionKeys(): array
    {
        $role = $this->roleRef;
        if ($role === null) {
            return [];
        }

        return $role->permissions->pluck('key')->all();
    }
}
