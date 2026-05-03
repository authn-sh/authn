<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-environment RS256 keypair. The public half is exposed via JWKS;
 * the private half is encrypted at rest with Laravel's app key (the
 * pluggable secrets-driver lands later — see PLAN §17.6).
 *
 * @property string $id
 * @property string $environment_id
 * @property string $algorithm
 * @property array $public_jwk
 * @property string $encrypted_private_pem
 * @property string $status
 * @property ?\DateTimeInterface $published_at
 * @property ?\DateTimeInterface $activated_at
 * @property ?\DateTimeInterface $retire_at
 * @property ?\DateTimeInterface $expired_at
 */
class SigningKey extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRING = 'retiring';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_RETIRING,
        self::STATUS_EXPIRED,
    ];

    protected string $idPrefix = 'kid_';

    protected $fillable = [
        'environment_id',
        'algorithm',
        'public_jwk',
        'encrypted_private_pem',
        'status',
        'published_at',
        'activated_at',
        'retire_at',
        'expired_at',
    ];

    protected $hidden = [
        'encrypted_private_pem',
    ];

    protected function casts(): array
    {
        return [
            'public_jwk' => 'array',
            'published_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'retire_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Decrypt and return the private PEM. Use sparingly — only the JWT
     * issuance pipeline (AU-6) and rotation jobs (AU-19) should touch this.
     */
    public function privatePem(): string
    {
        return Crypt::decryptString($this->encrypted_private_pem);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPublished(): bool
    {
        // active, retiring, and pending keys are all in the published set
        // (see PLAN §10.4.1) — the JWKS includes them so a token signed
        // by any one of them can be verified.
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_RETIRING], true);
    }
}
