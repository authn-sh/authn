<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Minimal v0.1 Organization shape. The full v0.2 surface (logos, public/
 * private metadata, members_count, max_allowed_memberships, …) lands when
 * AU-13 / v0.2 lights up the org features.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $name
 * @property string $slug
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
        'created_by_user_id',
    ];

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
            ->withPivot(['id', 'role', 'created_at', 'updated_at']);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_organization_id');
    }
}
