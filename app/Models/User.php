<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use App\Observers\UserObserver;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

/**
 * Full v0.1 User shape per PLAN §4.2. Email storage lives on
 * `email_addresses` (one row per email, primary tracked here via
 * `primary_email_address_id`). MFA, lockout, and metadata fields are
 * present so AU-9 / AU-10 can branch on them without further schema work.
 *
 * @property string $id
 * @property string $environment_id
 * @property ?string $external_id
 * @property ?string $username
 * @property ?string $first_name
 * @property ?string $last_name
 * @property ?string $image_url
 * @property bool $has_image
 * @property ?string $primary_email_address_id
 * @property ?string $primary_phone_number_id
 * @property bool $phone_number_enabled
 * @property ?string $password_hash
 * @property ?\DateTimeInterface $password_changed_at
 * @property bool $two_factor_enabled
 * @property bool $totp_enabled
 * @property bool $backup_code_enabled
 * @property ?\DateTimeInterface $mfa_enabled_at
 * @property ?\DateTimeInterface $mfa_disabled_at
 * @property bool $banned
 * @property bool $locked
 * @property ?\DateTimeInterface $lockout_expires_at
 * @property ?\DateTimeInterface $last_sign_in_at
 * @property ?\DateTimeInterface $last_active_at
 * @property bool $delete_self_enabled
 * @property array $public_metadata
 * @property array $private_metadata
 * @property array $unsafe_metadata
 * @property ?string $locale
 * @property-read int $passkey_count
 */
#[ObservedBy([UserObserver::class])]
class User extends Model implements AuthenticatableContract
{
    use Authorizable;
    use HasFactory;
    use HasPrefixedUlid;
    use Notifiable;
    use SoftDeletes;

    protected string $idPrefix = 'user_';

    protected $fillable = [
        'environment_id',
        'external_id',
        'username',
        'first_name',
        'last_name',
        'image_url',
        'has_image',
        'primary_email_address_id',
        'primary_phone_number_id',
        'phone_number_enabled',
        'password',
        'password_hash',
        'password_changed_at',
        'password_imported',
        'password_hasher',
        'two_factor_enabled',
        'totp_enabled',
        'backup_code_enabled',
        'mfa_enabled_at',
        'mfa_disabled_at',
        'banned',
        'locked',
        'lockout_expires_at',
        'last_sign_in_at',
        'last_active_at',
        'delete_self_enabled',
        'public_metadata',
        'private_metadata',
        'unsafe_metadata',
        'locale',
    ];

    protected $hidden = [
        'password_hash',
        'private_metadata',
    ];

    protected function casts(): array
    {
        return [
            'has_image' => 'boolean',
            'phone_number_enabled' => 'boolean',
            'password_imported' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'totp_enabled' => 'boolean',
            'backup_code_enabled' => 'boolean',
            'banned' => 'boolean',
            'locked' => 'boolean',
            'delete_self_enabled' => 'boolean',
            'password_changed_at' => 'immutable_datetime',
            'mfa_enabled_at' => 'immutable_datetime',
            'mfa_disabled_at' => 'immutable_datetime',
            'lockout_expires_at' => 'immutable_datetime',
            'last_sign_in_at' => 'immutable_datetime',
            'last_active_at' => 'immutable_datetime',
            'public_metadata' => 'array',
            'private_metadata' => 'array',
            'unsafe_metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function emailAddresses(): HasMany
    {
        return $this->hasMany(EmailAddress::class);
    }

    public function externalAccounts(): HasMany
    {
        return $this->hasMany(ExternalAccount::class);
    }

    public function enterpriseAccounts(): HasMany
    {
        return $this->hasMany(EnterpriseAccount::class);
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    protected function passkeyCount(): Attribute
    {
        return Attribute::get(fn (): int => $this->passkeys()->whereNotNull('verified_at')->count());
    }

    public function primaryEmailAddress(): BelongsTo
    {
        return $this->belongsTo(EmailAddress::class, 'primary_email_address_id');
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class);
    }

    public function primaryPhoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'primary_phone_number_id');
    }

    public function organizationMemberships(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot(['id', 'role_id', 'created_at', 'updated_at']);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * Per-request memo for `hasOrgPermission()` lookups. Keyed by
     * `<org_id>:<perm_key>`. Cleared when the model goes out of scope.
     *
     * @var array<string, bool>
     */
    private array $orgPermissionCache = [];

    /**
     * Whether this user holds `$key` inside `$org` via their membership's
     * role's permission set. `false` when `$org` is null (callers must scope
     * explicitly) or the user has no membership in that org.
     */
    public function hasOrgPermission(string $key, ?Organization $org): bool
    {
        if ($org === null) {
            return false;
        }

        $cacheKey = $org->id.':'.$key;
        if (array_key_exists($cacheKey, $this->orgPermissionCache)) {
            return $this->orgPermissionCache[$cacheKey];
        }

        $membership = $this->memberships()
            ->where('organization_id', $org->id)
            ->with('role.permissions')
            ->first();

        $allowed = $membership !== null
            && $membership->role !== null
            && $membership->role->permissions->contains('key', $key);

        return $this->orgPermissionCache[$cacheKey] = $allowed;
    }

    /**
     * Whether this user holds the role with key `$key` in `$org`.
     */
    public function hasOrgRole(string $key, ?Organization $org): bool
    {
        if ($org === null) {
            return false;
        }

        return $this->memberships()
            ->where('organization_id', $org->id)
            ->whereHas('role', fn ($q) => $q->where('key', $key))
            ->exists();
    }

    /**
     * Drop the per-request permission memo. Useful in tests when a role's
     * permission set is mutated mid-test.
     */
    public function flushOrgPermissionCache(): void
    {
        $this->orgPermissionCache = [];
    }

    /**
     * Mass-assignable `password` attribute. Hashing happens here so test
     * factories and BAPI / FAPI controllers never see plaintext stored.
     */
    protected function password(): Attribute
    {
        return Attribute::set(function (?string $value, array $attributes): array {
            if ($value === null || $value === '') {
                return ['password_hash' => $attributes['password_hash'] ?? null];
            }

            return [
                'password_hash' => Hash::make($value),
                'password_changed_at' => now(),
            ];
        });
    }

    public function setPassword(string $plaintext): void
    {
        $this->password = $plaintext;
    }

    public function checkPassword(string $plaintext): bool
    {
        if ($this->password_hash === null) {
            return false;
        }
        // Imported hashes use the algorithm captured at import time. The
        // active hasher (argon2id) refuses to verify foreign formats, so
        // route bcrypt imports through Hash::driver('bcrypt'). PBKDF2 / scrypt
        // verifiers land in AU-18.
        if ($this->password_imported && in_array($this->password_hasher, ['bcrypt'], true)) {
            return Hash::driver('bcrypt')->check($plaintext, $this->password_hash);
        }

        return Hash::check($plaintext, $this->password_hash);
    }

    /**
     * Per PLAN §4.2: cleared automatically when `lockout_expires_at` passes,
     * or manually via `POST /v1/users/{id}/unlock`. Also exposes the
     * `lockout_expires_in_seconds` derived field SDKs render in
     * `<UserProfile />` lockout banners.
     */
    protected function lockoutExpiresInSeconds(): Attribute
    {
        return Attribute::get(function (): ?int {
            if (! $this->locked || $this->lockout_expires_at === null) {
                return null;
            }
            $remaining = $this->lockout_expires_at->getTimestamp() - now()->getTimestamp();

            return max(0, $remaining);
        });
    }

    /* ----- Illuminate\Contracts\Auth\Authenticatable bridge ----- */

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        // No remember-me in v0.1; sessions are managed by our own JWT layer.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
