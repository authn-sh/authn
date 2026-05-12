<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use App\Support\Base64Url;
use Database\Factories\ScimTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCIM bearer token. Plaintext (`scim_<base64url>`) is shown once at
 * issue time; only `hashed_token` (sha256 of the plaintext) is persisted.
 * `prefix` is the visible leading slug used in the dashboard / SDKs
 * ("scim_XX…") to help operators distinguish rows without revealing the
 * full secret.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $hashed_token
 * @property string $prefix
 * @property ?string $organization_id
 * @property ?string $enterprise_connection_id
 * @property string $name
 * @property string $created_by_user_id
 * @property ?\DateTimeInterface $last_used_at
 * @property ?\DateTimeInterface $expires_at
 * @property ?\DateTimeInterface $revoked_at
 */
class ScimToken extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const TOKEN_PREFIX = 'scim_';

    public const PLAINTEXT_BYTES = 32;

    protected string $idPrefix = 'scimt_';

    protected $fillable = [
        'environment_id',
        'hashed_token',
        'prefix',
        'organization_id',
        'enterprise_connection_id',
        'name',
        'created_by_user_id',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'hashed_token',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    protected static function newFactory(): ScimTokenFactory
    {
        return ScimTokenFactory::new();
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function enterpriseConnection(): BelongsTo
    {
        return $this->belongsTo(EnterpriseConnection::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Generate a fresh plaintext token and return both it and its sha256 hash.
     * Callers persist the hash + prefix; the plaintext is shown to the user
     * exactly once at issue time.
     *
     * @return array{plaintext: string, hash: string, prefix: string}
     */
    public static function mintPlaintext(): array
    {
        $body = Base64Url::encode(random_bytes(self::PLAINTEXT_BYTES));
        $plaintext = self::TOKEN_PREFIX.$body;

        return [
            'plaintext' => $plaintext,
            'hash' => hash('sha256', $plaintext, true),
            'prefix' => substr($plaintext, 0, 12),
        ];
    }

    /**
     * Locate the (env-scoped) ScimToken row matching the supplied plaintext
     * bearer. Returns null when no row matches, when the row was revoked,
     * or when the row's `expires_at` is in the past.
     */
    public static function verify(string $plaintext): ?self
    {
        if (! str_starts_with($plaintext, self::TOKEN_PREFIX)) {
            return null;
        }

        $hash = hash('sha256', $plaintext, true);
        $row = self::query()->where('hashed_token', $hash)->first();
        if ($row === null) {
            return null;
        }

        return $row->isUsable() ? $row : null;
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || ! $this->expires_at->isPast();
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->revoked_at = now();
            $this->save();
        }
    }

    /**
     * @param  Builder<ScimToken>  $query
     * @return Builder<ScimToken>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
