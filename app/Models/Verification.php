<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polymorphic proof-of-ownership state. Each Verification row tracks one
 * outstanding challenge (an email code, a password attempt, an OAuth
 * callback, …) attached to a parent (EmailAddress, SignInAttempt,
 * SignUpAttempt, …).
 *
 * Lifecycle:
 *   created → unverified
 *   on success: → verified (sets `verified_at`)
 *   on max_attempts: → failed
 *   on TTL elapse: → expired (scrubbed by AU-19's reaper)
 *   on cross-flow handoff: → transferable (with a single-use transfer_token)
 *
 * @property string $id
 * @property string $environment_id
 * @property string $verifiable_type
 * @property string $verifiable_id
 * @property string $strategy
 * @property string $status
 * @property int $attempts
 * @property \DateTimeInterface $expire_at
 * @property ?string $external_verification_redirect_url
 * @property ?string $nonce
 * @property ?string $originating_client_id
 * @property ?string $redeemed_by_client_id
 * @property ?string $transfer_token
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?\DateTimeInterface $verified_at
 */
class Verification extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_TRANSFERABLE = 'transferable';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_UNVERIFIED,
        self::STATUS_VERIFIED,
        self::STATUS_TRANSFERABLE,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
    ];

    public const STRATEGY_PASSWORD = 'password';

    public const STRATEGY_EMAIL_CODE = 'email_code';

    public const STRATEGY_RESET_PASSWORD_EMAIL_CODE = 'reset_password_email_code';

    public const STRATEGY_TICKET = 'ticket';

    public const STRATEGY_DOMAIN_DNS_TXT = 'domain_dns_txt';

    public const STRATEGY_TOTP = 'totp';

    public const STRATEGY_BACKUP_CODE = 'backup_code';

    public const STRATEGY_PHONE_CODE = 'phone_code';

    public const STRATEGY_PASSKEY = 'passkey';

    public const STRATEGY_ENTERPRISE_SSO = 'enterprise_sso';

    public const STRATEGY_SAML = 'saml';

    public const STRATEGIES = [
        self::STRATEGY_PASSWORD,
        self::STRATEGY_EMAIL_CODE,
        self::STRATEGY_RESET_PASSWORD_EMAIL_CODE,
        self::STRATEGY_TICKET,
        self::STRATEGY_DOMAIN_DNS_TXT,
        self::STRATEGY_TOTP,
        self::STRATEGY_BACKUP_CODE,
        self::STRATEGY_PHONE_CODE,
        self::STRATEGY_PASSKEY,
        self::STRATEGY_ENTERPRISE_SSO,
        self::STRATEGY_SAML,
    ];

    /**
     * Regex for the v0.4 `oauth_<provider_key>` strategy family. One match
     * per registered `OauthProvider` row; per-env enabled-providers narrowing
     * lives on `StrategyResolver`.
     */
    public const OAUTH_STRATEGY_PATTERN = '/^oauth_[a-z][a-z0-9_]*$/';

    protected string $idPrefix = 'ver_';

    protected $fillable = [
        'environment_id',
        'verifiable_type',
        'verifiable_id',
        'strategy',
        'status',
        'attempts',
        'was_test',
        'expire_at',
        'external_verification_redirect_url',
        'nonce',
        'originating_client_id',
        'redeemed_by_client_id',
        'transfer_token',
        'error_code',
        'error_message',
        'verified_at',
    ];

    protected $hidden = [
        'transfer_token',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'int',
            'was_test' => 'boolean',
            'expire_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(VerificationCode::class);
    }

    public function isExpired(): bool
    {
        return $this->expire_at->isPast();
    }

    /**
     * @return list<string>
     */
    public static function strategies(): array
    {
        return self::STRATEGIES;
    }

    /**
     * Whether `$strategy` is one this codebase recognises. Accepts the
     * fixed enum plus any `oauth_<provider_key>` matching the registered
     * pattern. Per-environment "is this provider actually enabled?" narrowing
     * lives on StrategyResolver — this is the syntactic gate.
     */
    public static function isValidStrategy(string $strategy): bool
    {
        if (in_array($strategy, self::STRATEGIES, true)) {
            return true;
        }

        return preg_match(self::OAUTH_STRATEGY_PATTERN, $strategy) === 1;
    }
}
