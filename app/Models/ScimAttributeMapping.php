<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use App\Scim\DefaultAttributeMappings;
use Database\Factories\ScimAttributeMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per per-org override of a SCIM <-> internal attribute mapping.
 * Defaults are NOT stored — they live in `DefaultAttributeMappings` and
 * are merged at read time via `resolveFor()`. Storing only overrides keeps
 * the table small and lets us evolve defaults without backfill.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property ?string $enterprise_connection_id
 * @property string $source_attribute
 * @property string $target_attribute
 * @property ?string $transform
 */
class ScimAttributeMapping extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'scimm_';

    protected $fillable = [
        'environment_id',
        'organization_id',
        'enterprise_connection_id',
        'source_attribute',
        'target_attribute',
        'transform',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    protected static function newFactory(): ScimAttributeMappingFactory
    {
        return ScimAttributeMappingFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function enterpriseConnection(): BelongsTo
    {
        return $this->belongsTo(EnterpriseConnection::class);
    }

    /**
     * Effective mapping for `$organization` as a flat
     * `[source_attribute => ['target' => string, 'transform' => ?string]]`
     * map. Built by overlaying the org's stored overrides on top of
     * `DefaultAttributeMappings::DEFAULTS`. Per-connection rows scope
     * further — `null` connection means "any connection in the org".
     *
     * @return array<string, array{target: string, transform: ?string}>
     */
    public static function resolveFor(Organization $organization, ?EnterpriseConnection $connection = null): array
    {
        $resolved = [];
        foreach (DefaultAttributeMappings::DEFAULTS as $source => $target) {
            $resolved[$source] = ['target' => $target, 'transform' => null];
        }

        $query = self::query()->where('organization_id', $organization->id);
        if ($connection !== null) {
            $query->where(function ($q) use ($connection): void {
                $q->whereNull('enterprise_connection_id')
                    ->orWhere('enterprise_connection_id', $connection->id);
            });
        }

        foreach ($query->get() as $row) {
            $resolved[$row->source_attribute] = [
                'target' => $row->target_attribute,
                'transform' => $row->transform,
            ];
        }

        return $resolved;
    }
}
