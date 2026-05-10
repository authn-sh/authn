<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use App\Http\Resources\PhoneNumberResource;
use App\Observers\PhoneNumberObserver;
use App\Webhooks\Emitter;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One row per phone a user has registered. Verification proof lives on the
 * polymorphic `Verification` model via the morphMany relation; the
 * `verified_at` timestamp is the cached "this phone is good" flag the rest
 * of the codebase reads.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $user_id
 * @property string $phone_number
 * @property ?\DateTimeInterface $verified_at
 * @property bool $is_primary
 * @property bool $reserved_for_second_factor
 * @property bool $default_second_factor
 * @property ?string $linked_to_external_account_id
 * @property ?string $current_challenge_id
 */
#[ObservedBy([PhoneNumberObserver::class])]
class PhoneNumber extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'phn_';

    protected $table = 'phone_numbers';

    protected $fillable = [
        'environment_id',
        'user_id',
        'phone_number',
        'verified_at',
        'is_primary',
        'reserved_for_second_factor',
        'default_second_factor',
        'linked_to_external_account_id',
        'current_challenge_id',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'immutable_datetime',
            'is_primary' => 'boolean',
            'reserved_for_second_factor' => 'boolean',
            'default_second_factor' => 'boolean',
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

    public function verifications(): MorphMany
    {
        return $this->morphMany(Verification::class, 'verifiable');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Flip `verified_at` to `now()` and refresh the user's
     * `phone_number_enabled` flag in lock-step. Idempotent — calling on an
     * already-verified row is a no-op.
     */
    public function markVerified(): void
    {
        if ($this->verified_at !== null) {
            return;
        }

        $this->forceFill(['verified_at' => now()])->save();

        User::query()
            ->withoutGlobalScopes()
            ->whereKey($this->user_id)
            ->update(['phone_number_enabled' => true]);

        $env = Environment::query()->withoutGlobalScopes()->whereKey($this->environment_id)->first();
        app(Emitter::class)->emit('phoneNumber.verified', PhoneNumberResource::from($this->fresh() ?? $this), $env);
    }
}
