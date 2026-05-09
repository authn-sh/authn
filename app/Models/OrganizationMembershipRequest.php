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
 * @property string $user_id
 * @property string $status
 */
class OrganizationMembershipRequest extends Model
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

    protected string $idPrefix = 'orgreq_';

    protected $table = 'organization_membership_requests';

    protected $fillable = [
        'environment_id',
        'organization_id',
        'user_id',
        'status',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
