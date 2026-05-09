<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $environment_id
 * @property string $name
 * @property string $slug
 * @property ?string $image_path
 * @property int $members_count
 * @property int $pending_invitations_count
 * @property ?int $max_allowed_memberships
 * @property bool $admin_delete_enabled
 * @property array $public_metadata
 * @property array $private_metadata
 * @property ?string $created_by_user_id
 */
class Organization extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'org_';

    protected $fillable = [
        'environment_id',
        'name',
        'slug',
        'image_path',
        'members_count',
        'pending_invitations_count',
        'max_allowed_memberships',
        'admin_delete_enabled',
        'public_metadata',
        'private_metadata',
        'created_by_user_id',
    ];

    protected $hidden = [
        'private_metadata',
    ];

    protected function casts(): array
    {
        return [
            'admin_delete_enabled' => 'boolean',
            'members_count' => 'integer',
            'pending_invitations_count' => 'integer',
            'max_allowed_memberships' => 'integer',
            'public_metadata' => 'array',
            'private_metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['id', 'role', 'role_id', 'created_at', 'updated_at']);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(OrganizationDomain::class);
    }

    public function membershipRequests(): HasMany
    {
        return $this->hasMany(OrganizationMembershipRequest::class);
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_organization_id');
    }
}
