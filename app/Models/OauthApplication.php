<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use App\Support\Base64Url;
use Database\Factories\OauthApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * v0.7 OAuth provider mode — registered third-party application that
 * authenticates against authn.sh as the IdP. `client_id` is public
 * (`oac_pub_...`); the plaintext client secret is returned exactly once
 * on create / rotate-secret and only `hashed_client_secret` (sha256 of
 * the plaintext) is persisted. Public clients (`is_public=true`) skip
 * the secret entirely and rely on PKCE.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $name
 * @property string $client_id
 * @property ?string $hashed_client_secret
 * @property array<int, string> $callback_urls
 * @property array<int, string> $scopes
 * @property bool $is_public
 * @property ?\DateTimeInterface $removed_at
 */
class OauthApplication extends Model
{
    use HasFactory;
    use HasPrefixedUlid;
    use SoftDeletes;

    public const CLIENT_ID_PREFIX = 'oac_pub_';

    public const SECRET_PREFIX = 'oac_sec_';

    public const SECRET_BYTES = 32;

    public const DELETED_AT = 'removed_at';

    protected string $idPrefix = 'oac_';

    protected $fillable = [
        'environment_id',
        'name',
        'client_id',
        'hashed_client_secret',
        'callback_urls',
        'scopes',
        'is_public',
    ];

    protected $hidden = [
        'hashed_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'callback_urls' => 'array',
            'scopes' => 'array',
            'is_public' => 'boolean',
            'removed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    protected static function newFactory(): OauthApplicationFactory
    {
        return OauthApplicationFactory::new();
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function authorizationGrants(): HasMany
    {
        return $this->hasMany(AuthorizationGrant::class);
    }

    /**
     * Mint a fresh public `client_id`. Always called on create so callers
     * never have to remember the prefix discipline.
     */
    public static function mintClientId(): string
    {
        return self::CLIENT_ID_PREFIX.Base64Url::encode(random_bytes(16));
    }

    /**
     * Mint a fresh plaintext client secret and return both it and its
     * sha256 hash. Callers persist the hash; the plaintext is shown to
     * the operator exactly once at create / rotate time.
     *
     * @return array{plaintext: string, hash: string}
     */
    public static function mintClientSecret(): array
    {
        $plaintext = self::SECRET_PREFIX.Base64Url::encode(random_bytes(self::SECRET_BYTES));

        return [
            'plaintext' => $plaintext,
            'hash' => hash('sha256', $plaintext),
        ];
    }

    /**
     * Constant-time check of a plaintext client secret against the stored
     * hash. Returns false for public clients (no secret on file).
     */
    public function verifyClientSecret(string $plaintext): bool
    {
        if ($this->is_public || $this->hashed_client_secret === null) {
            return false;
        }

        return hash_equals($this->hashed_client_secret, hash('sha256', $plaintext));
    }
}
