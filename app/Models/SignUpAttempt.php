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
 * State machine for one in-progress sign-up. Drives the FAPI
 * `/v1/client/sign-ups/...` surface in AU-10.
 *
 * Status flow (PLAN §9.2):
 *   missing_requirements
 *     → complete
 *     → transferable    (OAuth attempt found an existing user)
 *     → abandoned       (24h `abandon_at` swept by AU-19)
 *
 * @property string $id
 * @property string $environment_id
 * @property string $client_id
 * @property string $status
 * @property ?string $email_address
 * @property ?string $phone_number
 * @property ?string $username
 * @property ?string $first_name
 * @property ?string $last_name
 * @property ?string $password_hash
 * @property array $unsafe_metadata
 * @property array $public_metadata
 * @property array $verifications
 * @property array $missing_fields
 * @property array $unverified_fields
 * @property ?string $created_session_id
 * @property ?string $created_user_id
 * @property \DateTimeInterface $abandon_at
 * @property ?string $transfer_token
 * @property bool $was_test
 */
class SignUpAttempt extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const STATUS_MISSING_REQUIREMENTS = 'missing_requirements';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_TRANSFERABLE = 'transferable';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUSES = [
        self::STATUS_MISSING_REQUIREMENTS,
        self::STATUS_COMPLETE,
        self::STATUS_TRANSFERABLE,
        self::STATUS_ABANDONED,
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_MISSING_REQUIREMENTS => [
            self::STATUS_COMPLETE,
            self::STATUS_TRANSFERABLE,
            self::STATUS_ABANDONED,
        ],
        self::STATUS_TRANSFERABLE => [
            self::STATUS_COMPLETE,
            self::STATUS_ABANDONED,
        ],
        self::STATUS_COMPLETE => [],
        self::STATUS_ABANDONED => [],
    ];

    public const DEFAULT_ABANDON_HOURS = 24;

    protected string $idPrefix = 'sui_';

    protected $fillable = [
        'environment_id',
        'client_id',
        'status',
        'email_address',
        'phone_number',
        'username',
        'first_name',
        'last_name',
        'password_hash',
        'unsafe_metadata',
        'public_metadata',
        'verifications',
        'missing_fields',
        'unverified_fields',
        'created_session_id',
        'created_user_id',
        'abandon_at',
        'transfer_token',
        'was_test',
        'captcha_token',
        'captcha_widget_type',
        'captcha_error',
    ];

    protected $hidden = [
        'password_hash',
        'transfer_token',
    ];

    protected function casts(): array
    {
        return [
            'abandon_at' => 'immutable_datetime',
            'was_test' => 'boolean',
            'unsafe_metadata' => 'array',
            'public_metadata' => 'array',
            'verifications' => 'array',
            'missing_fields' => 'array',
            'unverified_fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);

        static::creating(function (self $attempt): void {
            if (empty($attempt->status)) {
                $attempt->status = self::STATUS_MISSING_REQUIREMENTS;
            }
            if (empty($attempt->abandon_at)) {
                $attempt->abandon_at = now()->addHours(self::DEFAULT_ABANDON_HOURS);
            }
            // SQLite (test) and Postgres differ in how they surface jsonb
            // defaults — pin the model layer so consumers always see arrays.
            foreach (['unsafe_metadata', 'public_metadata', 'verifications', 'missing_fields', 'unverified_fields'] as $col) {
                if ($attempt->getAttribute($col) === null) {
                    $attempt->setAttribute($col, []);
                }
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
                    "Illegal SignUpAttempt transition: {$from} → {$to}"
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

    public function createdSession(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'created_session_id');
    }

    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function verifications(): MorphMany
    {
        return $this->morphMany(Verification::class, 'verifiable');
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }
}
