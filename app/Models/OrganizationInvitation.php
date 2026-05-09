<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $email_address
 * @property string $role_id
 * @property ?string $inviter_user_id
 * @property ?string $redirect_url
 * @property string $status
 * @property array $public_metadata
 * @property ?\DateTimeInterface $expires_at
 * @property ?int $ticket_verification_code_id
 */
class OrganizationInvitation extends Model
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

    protected string $idPrefix = 'orginv_';

    protected $fillable = [
        'environment_id',
        'organization_id',
        'email_address',
        'role_id',
        'inviter_user_id',
        'redirect_url',
        'status',
        'public_metadata',
        'expires_at',
        'ticket_verification_code_id',
    ];

    protected function casts(): array
    {
        return [
            'public_metadata' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(VerificationCode::class, 'ticket_verification_code_id');
    }
}
