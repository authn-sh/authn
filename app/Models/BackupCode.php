<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-use recovery code. Plaintext is shown once at generation and
 * never again; only the Argon2id hash is persisted.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $user_id
 * @property string $code_hash
 * @property ?\DateTimeInterface $consumed_at
 * @property \DateTimeInterface $created_at
 */
class BackupCode extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const UPDATED_AT = null;

    protected string $idPrefix = 'bcc_';

    protected $fillable = [
        'environment_id',
        'user_id',
        'code_hash',
        'consumed_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'consumed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function markConsumed(): void
    {
        $this->forceFill(['consumed_at' => now()])->save();
    }
}
