<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable event record (one row per Emitter::emit). The `type`
 * vocabulary mirrors PLAN §14.3.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $type
 * @property array $data
 * @property bool $was_test
 * @property \DateTimeInterface $created_at
 */
class WebhookEvent extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public $timestamps = false;

    protected string $idPrefix = 'evt_';

    protected $fillable = [
        'environment_id',
        'type',
        'data',
        'was_test',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'was_test' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);

        static::creating(function (self $event): void {
            if (empty($event->created_at)) {
                $event->created_at = now();
            }
        });
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
