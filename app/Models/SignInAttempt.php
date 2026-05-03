<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;

/**
 * State machine for one in-progress sign-in. Drives the FAPI
 * `/v1/client/sign_ins/...` surface in AU-9.
 *
 * Status flow (PLAN §9.1):
 *   needs_identifier
 *     → needs_first_factor
 *         → needs_second_factor (when MFA enabled; v0.3+)
 *             → complete
 *         → needs_new_password (reset-password flow)
 *             → complete
 *         → complete
 *     → needs_client_trust (post-v1)
 *   transferable / abandoned can be reached from any non-terminal state.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $client_id
 * @property string $status
 * @property ?string $identifier
 * @property ?string $first_factor_verification_id
 * @property ?string $second_factor_verification_id
 * @property ?string $created_session_id
 * @property \DateTimeInterface $abandon_at
 * @property ?string $transfer_token
 * @property bool $was_test
 */
class SignInAttempt extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const STATUS_NEEDS_IDENTIFIER = 'needs_identifier';

    public const STATUS_NEEDS_FIRST_FACTOR = 'needs_first_factor';

    public const STATUS_NEEDS_SECOND_FACTOR = 'needs_second_factor';

    public const STATUS_NEEDS_NEW_PASSWORD = 'needs_new_password';

    public const STATUS_NEEDS_CLIENT_TRUST = 'needs_client_trust';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUSES = [
        self::STATUS_NEEDS_IDENTIFIER,
        self::STATUS_NEEDS_FIRST_FACTOR,
        self::STATUS_NEEDS_SECOND_FACTOR,
        self::STATUS_NEEDS_NEW_PASSWORD,
        self::STATUS_NEEDS_CLIENT_TRUST,
        self::STATUS_COMPLETE,
        self::STATUS_ABANDONED,
    ];

    /**
     * Allowed status transitions. The validator on `saving` enforces this —
     * services in AU-9 don't have to litter `if`-guards; bad transitions
     * fail loudly at the model layer.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_NEEDS_IDENTIFIER => [
            self::STATUS_NEEDS_FIRST_FACTOR,
            self::STATUS_COMPLETE,            // ticket strategy short-circuits
            self::STATUS_ABANDONED,
        ],
        self::STATUS_NEEDS_FIRST_FACTOR => [
            self::STATUS_NEEDS_SECOND_FACTOR,
            self::STATUS_NEEDS_NEW_PASSWORD,
            self::STATUS_NEEDS_CLIENT_TRUST,
            self::STATUS_COMPLETE,
            self::STATUS_ABANDONED,
        ],
        self::STATUS_NEEDS_SECOND_FACTOR => [
            self::STATUS_COMPLETE,
            self::STATUS_ABANDONED,
        ],
        self::STATUS_NEEDS_NEW_PASSWORD => [
            self::STATUS_NEEDS_SECOND_FACTOR,
            self::STATUS_COMPLETE,
            self::STATUS_ABANDONED,
        ],
        self::STATUS_NEEDS_CLIENT_TRUST => [
            self::STATUS_COMPLETE,
            self::STATUS_ABANDONED,
        ],
        self::STATUS_COMPLETE => [],
        self::STATUS_ABANDONED => [],
    ];

    public const DEFAULT_ABANDON_HOURS = 24;

    protected string $idPrefix = 'sia_';

    protected $fillable = [
        'environment_id',
        'client_id',
        'status',
        'identifier',
        'first_factor_verification_id',
        'second_factor_verification_id',
        'created_session_id',
        'abandon_at',
        'transfer_token',
        'was_test',
        'captcha_token',
        'captcha_widget_type',
        'captcha_error',
    ];

    protected $hidden = [
        'transfer_token',
    ];

    protected function casts(): array
    {
        return [
            'abandon_at' => 'immutable_datetime',
            'was_test' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);

        static::creating(function (self $attempt): void {
            if (empty($attempt->status)) {
                $attempt->status = self::STATUS_NEEDS_IDENTIFIER;
            }
            if (empty($attempt->abandon_at)) {
                $attempt->abandon_at = now()->addHours(self::DEFAULT_ABANDON_HOURS);
            }
        });

        static::updating(function (self $attempt): void {
            if (! $attempt->isDirty('status')) {
                return;
            }
            $from = $attempt->getOriginal('status');
            $to = $attempt->status;
            if ($from === $to) {
                return;
            }
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new InvalidArgumentException(
                    "Illegal SignInAttempt transition: {$from} → {$to}"
                );
            }
        });
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function firstFactorVerification(): BelongsTo
    {
        return $this->belongsTo(Verification::class, 'first_factor_verification_id');
    }

    public function secondFactorVerification(): BelongsTo
    {
        return $this->belongsTo(Verification::class, 'second_factor_verification_id');
    }

    public function createdSession(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'created_session_id');
    }

    public function verifications(): MorphMany
    {
        return $this->morphMany(Verification::class, 'verifiable');
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function isAbandoned(): bool
    {
        return $this->status === self::STATUS_ABANDONED || $this->abandon_at->isPast();
    }
}
