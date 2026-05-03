<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal v0.1 User shape — just enough for the bootstrap operator and the
 * authentication flows that AU-9 / AU-10 wire up. AU-4 expands this with the
 * full PLAN §4.2 column set (external_id, image_url, MFA flags, lockout,
 * metadata, ...) plus a separate `email_addresses` table.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $email
 * @property ?\DateTimeInterface $email_verified_at
 * @property ?string $password_hash
 * @property ?string $first_name
 * @property ?string $last_name
 */
class User extends Model implements AuthenticatableContract
{
    use Authorizable;
    use HasFactory;
    use HasPrefixedUlid;
    use Notifiable;

    protected string $idPrefix = 'user_';

    protected $fillable = [
        'environment_id',
        'email',
        'email_verified_at',
        'password_hash',
        'first_name',
        'last_name',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function organizationMemberships(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot(['id', 'role', 'created_at', 'updated_at']);
    }

    public function setPassword(string $plaintext): void
    {
        $this->password_hash = Hash::make($plaintext);
    }

    public function checkPassword(string $plaintext): bool
    {
        return $this->password_hash !== null && Hash::check($plaintext, $this->password_hash);
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
