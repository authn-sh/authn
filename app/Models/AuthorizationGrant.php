<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Database\Factories\AuthorizationGrantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v0.7 OAuth provider mode — per-user consent record for an
 * OauthApplication. Created when the user accepts the consent screen
 * (AU-9); revoked from the Account Portal Authorized Apps panel (AU-10)
 * or implicitly when the parent OauthApplication is soft-deleted.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $oauth_application_id
 * @property string $user_id
 * @property array<int, string> $scopes
 * @property string $scopes_hash
 * @property \DateTimeInterface $granted_at
 * @property ?\DateTimeInterface $revoked_at
 */
class AuthorizationGrant extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'authgrant_';

    protected $fillable = [
        'environment_id',
        'oauth_application_id',
        'user_id',
        'scopes',
        'scopes_hash',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);

        static::creating(function (self $grant): void {
            if (empty($grant->scopes_hash)) {
                $grant->scopes_hash = self::hashScopes($grant->scopes ?? []);
            }
            if (empty($grant->granted_at)) {
                $grant->granted_at = now();
            }
        });
    }

    protected static function newFactory(): AuthorizationGrantFactory
    {
        return AuthorizationGrantFactory::new();
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function oauthApplication(): BelongsTo
    {
        return $this->belongsTo(OauthApplication::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->revoked_at = now();
            $this->save();
        }
    }

    /**
     * Stable hash of the granted scope set so a partial-unique index can
     * keep one active row per (user, app, scope-set). Sorted to make the
     * hash order-independent.
     *
     * @param  array<int, string>  $scopes
     */
    public static function hashScopes(array $scopes): string
    {
        $sorted = array_values(array_unique($scopes));
        sort($sorted);

        return hash('sha256', implode(' ', $sorted));
    }

    /**
     * @param  Builder<AuthorizationGrant>  $query
     * @return Builder<AuthorizationGrant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * @param  Builder<AuthorizationGrant>  $query
     * @return Builder<AuthorizationGrant>
     */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }
}
