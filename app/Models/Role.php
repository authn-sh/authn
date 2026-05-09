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
 * @property string $key
 * @property string $name
 * @property ?string $description
 * @property bool $is_creator_eligible
 * @property bool $is_default
 * @property bool $is_system
 */
class Role extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'role_';

    protected $fillable = [
        'environment_id',
        'key',
        'name',
        'description',
        'is_creator_eligible',
        'is_default',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_creator_eligible' => 'boolean',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
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

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class, 'role_id');
    }
}
