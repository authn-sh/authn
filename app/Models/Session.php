<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\Jwt\JwtTemplateNotFound;
use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One live authenticated session for one device for one user. Many can
 * coexist per Client when `InstanceSetting.sessions.multi_session = true`
 * (PLAN §13.5); only one per (Client, User) pair when false.
 *
 * The `__session` JWT cookie issued by AU-6 binds to a Session via the
 * `sid` claim; when this row's status leaves the active set (or its
 * `expire_at` passes), the next refresh fails and the SDK signs out.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $client_id
 * @property string $user_id
 * @property string $status
 * @property ?\DateTimeInterface $last_active_at
 * @property \DateTimeInterface $expire_at
 * @property ?\DateTimeInterface $abandon_at
 * @property ?string $last_active_organization_id
 * @property ?array $actor {iss, sub, sid} for impersonation
 * @property bool $was_test
 */
class Session extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ENDED = 'ended';

    public const STATUS_REMOVED = 'removed';

    public const STATUS_REPLACED = 'replaced';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_ENDED,
        self::STATUS_REMOVED,
        self::STATUS_REPLACED,
        self::STATUS_REVOKED,
        self::STATUS_ABANDONED,
    ];

    /** Sessions that are still alive. AU-6's `__session` mint refuses anything else. */
    public const LIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_ACTIVE,
            self::STATUS_EXPIRED,
            self::STATUS_ABANDONED,
            self::STATUS_REVOKED,
        ],
        self::STATUS_ACTIVE => [
            self::STATUS_EXPIRED,
            self::STATUS_ENDED,
            self::STATUS_REMOVED,
            self::STATUS_REPLACED,
            self::STATUS_REVOKED,
        ],
        // Terminal statuses — no further transitions allowed.
        self::STATUS_EXPIRED => [],
        self::STATUS_ENDED => [],
        self::STATUS_REMOVED => [],
        self::STATUS_REPLACED => [],
        self::STATUS_REVOKED => [],
        self::STATUS_ABANDONED => [],
    ];

    /**
     * Default session lifetime — `Environment.sessions.lifetime_seconds` will
     * override this once that settings blob is wired in AU-13. Matches
     * PLAN §13.5 (7 days).
     */
    public const DEFAULT_LIFETIME_SECONDS = 7 * 24 * 60 * 60;

    /** Default abandon window for `pending` sessions (waiting on MFA / step-up). */
    public const DEFAULT_PENDING_ABANDON_HOURS = 24;

    protected string $idPrefix = 'sess_';

    protected $fillable = [
        'environment_id',
        'client_id',
        'user_id',
        'status',
        'last_active_at',
        'expire_at',
        'abandon_at',
        'last_active_organization_id',
        'token_version',
        'actor',
        'was_test',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'immutable_datetime',
            'expire_at' => 'immutable_datetime',
            'abandon_at' => 'immutable_datetime',
            'actor' => 'array',
            'was_test' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);

        static::creating(function (self $session): void {
            if (empty($session->status)) {
                $session->status = self::STATUS_ACTIVE;
            }
            if (empty($session->expire_at)) {
                $session->expire_at = now()->addSeconds(self::DEFAULT_LIFETIME_SECONDS);
            }
            if (empty($session->abandon_at) && $session->status === self::STATUS_PENDING) {
                $session->abandon_at = now()->addHours(self::DEFAULT_PENDING_ABANDON_HOURS);
            }
            if (empty($session->last_active_at)) {
                $session->last_active_at = now();
            }
        });

        static::updating(function (self $session): void {
            if (! $session->isDirty('status')) {
                return;
            }
            $from = $session->getOriginal('status');
            $to = $session->status;
            if ($from === $to) {
                return;
            }
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new InvalidArgumentException(
                    "Illegal Session transition: {$from} → {$to}"
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SessionActivity::class)->orderByDesc('created_at');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function isImpersonation(): bool
    {
        return $this->actor !== null;
    }

    /**
     * Mint a session JWT. With `$template = null` (or `'default'`), returns
     * the env-default session token shape. With a custom `$template` name,
     * resolves the matching `JwtTemplate` row and renders custom claims via
     * `JwtTemplateRenderer`.
     *
     * @throws JwtTemplateNotFound when the supplied template name has no matching JwtTemplate row in this environment.
     */
    public function getToken(?string $template = null): string
    {
        return app(SessionTokenIssuer::class)->mint($this, $template)['jwt'];
    }
}
