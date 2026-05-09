<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BAPI-issued sign-up invitation. The full CRUD surface (issue, list, revoke,
 * resend) lands in AU-13; AU-10 only needs the columns here so the FAPI
 * `/v1/client/sign-ups` controller can redeem a `ticket` claim.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $email_address
 * @property string $status
 * @property array $public_metadata
 * @property ?string $redirect_url
 * @property ?string $redeemed_by_user_id
 * @property ?\DateTimeInterface $expires_at
 * @property ?\DateTimeInterface $accepted_at
 * @property ?\DateTimeInterface $revoked_at
 */
class Invitation extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
    ];

    protected string $idPrefix = 'inv_';

    protected $fillable = [
        'environment_id',
        'email_address',
        'status',
        'public_metadata',
        'redirect_url',
        'redeemed_by_user_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'template_slug',
    ];

    protected function casts(): array
    {
        return [
            'public_metadata' => 'array',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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

    public function isRedeemable(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
