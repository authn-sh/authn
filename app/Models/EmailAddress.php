<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use App\Observers\EmailAddressObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * One row per email a user has registered. Verification proof lives on
 * the polymorphic Verification model via the morphMany relation; the
 * `verified_at` timestamp is the cached "this email is good" flag the
 * rest of the codebase reads.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $user_id
 * @property string $email_address
 * @property ?\DateTimeInterface $verified_at
 * @property bool $is_primary
 * @property ?string $linked_to_external_account_id
 * @property ?string $current_challenge_id
 */
#[ObservedBy([EmailAddressObserver::class])]
class EmailAddress extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'eml_';

    protected $table = 'email_addresses';

    protected $fillable = [
        'environment_id',
        'user_id',
        'email_address',
        'verified_at',
        'is_primary',
        'linked_to_external_account_id',
        'current_challenge_id',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'immutable_datetime',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    /**
     * Lowercase the email on write. Uniqueness lives at the DB level via
     * the `(environment_id, email_address)` unique index — normalising
     * here ensures collisions are caught.
     */
    protected function emailAddress(): Attribute
    {
        return Attribute::set(fn (string $value) => Str::lower($value));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function verifications(): MorphMany
    {
        return $this->morphMany(Verification::class, 'verifiable');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
