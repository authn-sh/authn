<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The device-scoped state container for one browser install. Identified
 * by the `__client` cookie issued by the FAPI on first contact.
 *
 * Owns:
 *   - the active Sessions on this device (through `sessions()`),
 *   - the in-progress sign-in attempt, if any (`current_sign_in_attempt_id`),
 *   - the in-progress sign-up attempt, if any (`current_sign_up_attempt_id`),
 *   - the device fingerprint cached at the last `touch` (jsonb).
 *
 * @property string $id
 * @property string $environment_id
 * @property string $cookie_secret
 * @property ?string $last_active_session_id
 * @property ?string $current_sign_in_attempt_id
 * @property ?string $current_sign_up_attempt_id
 * @property array $device_fingerprint
 * @property ?\DateTimeInterface $last_active_at
 */
class Client extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'client_';

    protected $fillable = [
        'environment_id',
        'cookie_secret',
        'last_active_session_id',
        'current_sign_in_attempt_id',
        'current_sign_up_attempt_id',
        'device_fingerprint',
        'last_active_at',
    ];

    protected $hidden = [
        'cookie_secret',
    ];

    protected function casts(): array
    {
        return [
            'device_fingerprint' => 'array',
            'last_active_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);

        // Mint a fresh cookie_secret on creation if one wasn't supplied.
        static::creating(function (Client $client): void {
            if (empty($client->cookie_secret)) {
                $client->cookie_secret = self::mintCookieSecret();
            }
        });
    }

    public static function mintCookieSecret(): string
    {
        return Str::random(48);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function lastActiveSession(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'last_active_session_id');
    }

    public function currentSignInAttempt(): BelongsTo
    {
        return $this->belongsTo(SignInAttempt::class, 'current_sign_in_attempt_id');
    }

    public function currentSignUpAttempt(): BelongsTo
    {
        return $this->belongsTo(SignUpAttempt::class, 'current_sign_up_attempt_id');
    }
}
