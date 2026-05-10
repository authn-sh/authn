<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RFC 6238 TOTP secret enrolled by a user. At most one verified row per
 * (environment_id, user_id) — the AU-3 enrolment service enforces that
 * invariant by deleting any prior unverified row before inserting a new
 * attempt, so the schema does not carry a partial unique index.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $user_id
 * @property string $secret
 * @property string $algorithm
 * @property int $digits
 * @property int $period_seconds
 * @property ?\DateTimeInterface $verified_at
 */
class TotpSecret extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'totp_';

    protected $fillable = [
        'environment_id',
        'user_id',
        'secret',
        'algorithm',
        'digits',
        'period_seconds',
        'verified_at',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            // App-key-based encryption for now; per-env secret driver lands
            // separately and will re-wrap without changing this contract.
            'secret' => 'encrypted',
            'digits' => 'int',
            'period_seconds' => 'int',
            'verified_at' => 'immutable_datetime',
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

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
