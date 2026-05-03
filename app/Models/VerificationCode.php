<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The hashed code/link token a Verification was issued with. Separated
 * from Verification so we can prune secret material aggressively (AU-19's
 * PruneVerificationCodes job) while keeping the audit trail of attempts
 * and outcomes around.
 *
 * Internal-only — never returned over the API. Pure auto-increment id;
 * no prefix, no soft-delete.
 *
 * @property int $id
 * @property string $verification_id
 * @property string $code_hash SHA-256 of the cleartext code
 * @property string $purpose email_code | magic_link | reset_password_email_code | passwordless_email
 * @property \DateTimeInterface $expires_at
 * @property ?\DateTimeInterface $consumed_at
 */
class VerificationCode extends Model
{
    use HasFactory;

    public const PURPOSE_EMAIL_CODE = 'email_code';

    public const PURPOSE_MAGIC_LINK = 'magic_link';

    public const PURPOSE_RESET_PASSWORD_EMAIL_CODE = 'reset_password_email_code';

    public const PURPOSE_PASSWORDLESS_EMAIL = 'passwordless_email';

    public $timestamps = false;

    protected $fillable = [
        'verification_id',
        'code_hash',
        'purpose',
        'expires_at',
        'consumed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired();
    }
}
