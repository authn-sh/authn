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
 * @property string $kind secret | publishable
 * @property string $prefix
 * @property string $hashed_secret
 * @property ?string $name
 * @property ?\DateTimeInterface $last_used_at
 * @property ?\DateTimeInterface $revoked_at
 * @property ?string $created_by_user_id
 */
class ApiKey extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const KIND_SECRET = 'secret';

    public const KIND_PUBLISHABLE = 'publishable';

    public const KINDS = [self::KIND_SECRET, self::KIND_PUBLISHABLE];

    protected string $idPrefix = 'apik_';

    protected $fillable = [
        'environment_id',
        'kind',
        'prefix',
        'hashed_secret',
        'name',
        'last_used_at',
        'revoked_at',
        'created_by_user_id',
    ];

    protected $hidden = [
        'hashed_secret',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
