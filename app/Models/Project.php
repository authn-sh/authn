<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    /**
     * The reserved slug for the system project that owns operator
     * workspaces. See PLAN §4.1.
     */
    public const SYSTEM_SLUG = '_admin';

    protected string $idPrefix = 'prj_';

    protected $fillable = [
        'owner_organization_id',
        'name',
        'slug',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function ownerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owner_organization_id');
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    protected function isAdminProject(): Attribute
    {
        return Attribute::get(fn (): bool => $this->slug === self::SYSTEM_SLUG && $this->is_system);
    }
}
