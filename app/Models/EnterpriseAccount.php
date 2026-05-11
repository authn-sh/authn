<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Database\Factories\EnterpriseAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per (User, EnterpriseConnection, provider_user_id). `id_token`
 * column holds the encrypted OIDC ID token when present; SAML connections
 * leave it null. Unique on `(enterprise_connection_id, provider_user_id)`
 * prevents the same IdP subject linking twice.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $user_id
 * @property string $enterprise_connection_id
 * @property string $provider_user_id
 * @property ?string $email_address
 * @property bool $verified
 * @property array<string, mixed> $public_metadata
 * @property ?string $id_token
 * @property \DateTimeInterface $linked_at
 * @property ?\DateTimeInterface $last_signed_in_at
 * @property ?\DateTimeInterface $removed_at
 */
class EnterpriseAccount extends Model
{
    use HasFactory;
    use HasPrefixedUlid;
    use SoftDeletes;

    public const DELETED_AT = 'removed_at';

    protected string $idPrefix = 'entacc_';

    protected $fillable = [
        'environment_id',
        'user_id',
        'enterprise_connection_id',
        'provider_user_id',
        'email_address',
        'verified',
        'public_metadata',
        'id_token',
        'linked_at',
        'last_signed_in_at',
    ];

    protected $hidden = [
        'id_token',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'public_metadata' => 'array',
            'id_token' => 'encrypted',
            'linked_at' => 'immutable_datetime',
            'last_signed_in_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    protected static function newFactory(): EnterpriseAccountFactory
    {
        return EnterpriseAccountFactory::new();
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enterpriseConnection(): BelongsTo
    {
        return $this->belongsTo(EnterpriseConnection::class);
    }
}
