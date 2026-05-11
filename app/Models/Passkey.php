<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use Database\Factories\PasskeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per registered WebAuthn credential. The `credential_id` and
 * `public_key` blobs are encrypted at rest; the SHA-256 `credential_id_hash`
 * is the index column used by the sign-in path to find a passkey from the
 * authenticator's assertion without first decrypting every row.
 *
 * @property string $id
 * @property string $user_id
 * @property string $credential_id
 * @property string $credential_id_hash
 * @property string $public_key
 * @property int $sign_count
 * @property array<int, string> $transports
 * @property ?string $aaguid
 * @property ?string $nickname
 * @property ?\DateTimeInterface $last_used_at
 * @property ?\DateTimeInterface $verified_at
 * @property ?\DateTimeInterface $removed_at
 */
class Passkey extends Model
{
    use HasFactory;
    use HasPrefixedUlid;
    use SoftDeletes;

    public const TRANSPORT_USB = 'usb';

    public const TRANSPORT_NFC = 'nfc';

    public const TRANSPORT_BLE = 'ble';

    public const TRANSPORT_INTERNAL = 'internal';

    public const TRANSPORT_HYBRID = 'hybrid';

    public const TRANSPORT_SMART_CARD = 'smart-card';

    public const TRANSPORTS = [
        self::TRANSPORT_USB,
        self::TRANSPORT_NFC,
        self::TRANSPORT_BLE,
        self::TRANSPORT_INTERNAL,
        self::TRANSPORT_HYBRID,
        self::TRANSPORT_SMART_CARD,
    ];

    protected string $idPrefix = 'pkey_';

    public const DELETED_AT = 'removed_at';

    protected $fillable = [
        'user_id',
        'credential_id',
        'credential_id_hash',
        'public_key',
        'sign_count',
        'transports',
        'aaguid',
        'nickname',
        'last_used_at',
        'verified_at',
    ];

    protected $hidden = [
        'credential_id',
        'credential_id_hash',
        'public_key',
    ];

    protected function casts(): array
    {
        return [
            'credential_id' => 'encrypted',
            'public_key' => 'encrypted',
            'sign_count' => 'int',
            'transports' => 'array',
            'last_used_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): PasskeyFactory
    {
        return PasskeyFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }

    /**
     * Verified passkeys that have not been soft-deleted. Soft-deletes are
     * filtered automatically by the SoftDeletes trait, so this scope only
     * adds the `verified_at` predicate on top.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
