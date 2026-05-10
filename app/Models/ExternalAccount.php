<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (User, OauthProvider, provider_user_id). Holds the encrypted
 * access / refresh / id_token bundle returned by the IdP plus the cached
 * userinfo subset (`email_address`, `verified`, `scopes`, `public_metadata`)
 * the rest of the codebase needs without re-hitting the IdP.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $user_id
 * @property string $oauth_provider_id
 * @property string $provider_user_id
 * @property ?string $email_address
 * @property bool $verified
 * @property array<int, string> $scopes
 * @property array<string, mixed> $public_metadata
 * @property string $encrypted_access_token
 * @property ?string $encrypted_refresh_token
 * @property ?string $encrypted_id_token
 * @property ?\DateTimeInterface $access_token_expires_at
 * @property \DateTimeInterface $linked_at
 * @property ?\DateTimeInterface $last_signed_in_at
 */
class ExternalAccount extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'ext_';

    protected $fillable = [
        'environment_id',
        'user_id',
        'oauth_provider_id',
        'provider_user_id',
        'email_address',
        'verified',
        'scopes',
        'public_metadata',
        'encrypted_access_token',
        'encrypted_refresh_token',
        'encrypted_id_token',
        'access_token_expires_at',
        'linked_at',
        'last_signed_in_at',
    ];

    protected $hidden = [
        'encrypted_access_token',
        'encrypted_refresh_token',
        'encrypted_id_token',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'scopes' => 'array',
            'public_metadata' => 'array',
            'encrypted_access_token' => 'encrypted',
            'encrypted_refresh_token' => 'encrypted',
            'encrypted_id_token' => 'encrypted',
            'access_token_expires_at' => 'immutable_datetime',
            'linked_at' => 'immutable_datetime',
            'last_signed_in_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function oauthProvider(): BelongsTo
    {
        return $this->belongsTo(OauthProvider::class);
    }
}
