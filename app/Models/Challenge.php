<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Verification challenge attached to a SignInAttempt or SignUpAttempt. The
 * uniform sub-resource that replaces the per-factor `prepare-*` /
 * `attempt-*` endpoint pairs: the SDK creates a Challenge by picking a
 * strategy, then submits the user's response via `answer`. The Challenge
 * wraps an underlying Verification 1:1 — the Verification still does the
 * cryptographic work; the Challenge is the API-facing veneer.
 *
 * Lifecycle (mirror of openapi `Challenge.status`):
 *   created → pending
 *   on success → verified (parent state machine advances)
 *              → transferable (cross-flow handoff)
 *   on max_attempts → failed
 *   on TTL elapse  → expired
 *
 * @property string $id
 * @property string $environment_id
 * @property string $parent_type 'sign_in' | 'sign_up'
 * @property string $parent_id
 * @property string $step 'first' | 'second' | 'single'
 * @property string $strategy
 * @property string $status
 * @property string $verification_id
 * @property int $attempts
 * @property ?string $nonce
 * @property ?string $external_verification_redirect_url
 * @property ?string $error_code
 * @property ?string $error_message
 * @property \DateTimeInterface $expire_at
 */
class Challenge extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_TRANSFERABLE = 'transferable';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_TRANSFERABLE,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
    ];

    public const STEP_FIRST = 'first';

    public const STEP_SECOND = 'second';

    public const STEP_SINGLE = 'single';

    public const STEPS = [
        self::STEP_FIRST,
        self::STEP_SECOND,
        self::STEP_SINGLE,
    ];

    public const PARENT_SIGN_IN = 'sign_in';

    public const PARENT_SIGN_UP = 'sign_up';

    public const PARENT_TYPES = [
        self::PARENT_SIGN_IN,
        self::PARENT_SIGN_UP,
    ];

    public const MORPH_MAP = [
        self::PARENT_SIGN_IN => SignInAttempt::class,
        self::PARENT_SIGN_UP => SignUpAttempt::class,
    ];

    protected string $idPrefix = 'chal_';

    protected $fillable = [
        'environment_id',
        'parent_type',
        'parent_id',
        'step',
        'strategy',
        'status',
        'verification_id',
        'attempts',
        'nonce',
        'external_verification_redirect_url',
        'error_code',
        'error_message',
        'expire_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'int',
            'expire_at' => 'immutable_datetime',
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

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    /**
     * Resolves the parent attempt. `parent_type` is the short discriminator
     * ('sign_in' | 'sign_up'); MORPH_MAP translates it to the concrete model
     * class without registering a global Laravel morph map.
     */
    public function parent(): ?Model
    {
        $class = self::MORPH_MAP[$this->parent_type] ?? null;
        if ($class === null) {
            return null;
        }

        /** @var Model $class */
        return $class::query()->withoutGlobalScopes()->where('id', $this->parent_id)->first();
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expire_at->isPast();
    }
}
