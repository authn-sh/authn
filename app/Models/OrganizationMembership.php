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
 * @property string $role_id
 * @property array $public_metadata
 * @property array $private_metadata
 */
class OrganizationMembership extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'orgmem_';

    protected $table = 'organization_memberships';

    protected $fillable = [
        'environment_id',
        'organization_id',
        'user_id',
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

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Permission keys granted by this membership's role.
     */
    public function permissionKeys(): array
    {
        $role = $this->role;
        if ($role === null) {
            return [];
        }

        return $role->permissions->pluck('key')->all();
    }
}
