<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $name
 * @property bool $verified
 * @property string $enrollment_mode
 * @property ?string $current_challenge_id
 * @property ?string $affiliation_email_address
 * @property int $total_pending_invitations
 * @property int $total_pending_suggestions
 */
class OrganizationDomain extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const MODE_MANUAL_INVITATION = 'manual_invitation';

    public const MODE_AUTOMATIC_INVITATION = 'automatic_invitation';

    public const MODE_AUTOMATIC_SUGGESTION = 'automatic_suggestion';

    public const ENROLLMENT_MODES = [
        self::MODE_MANUAL_INVITATION,
        self::MODE_AUTOMATIC_INVITATION,
        self::MODE_AUTOMATIC_SUGGESTION,
    ];

    protected string $idPrefix = 'orgdom_';

    protected $fillable = [
        'environment_id',
        'organization_id',
        'name',
        'verified',
        'enrollment_mode',
        'current_challenge_id',
        'affiliation_email_address',
        'total_pending_invitations',
        'total_pending_suggestions',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'total_pending_invitations' => 'integer',
            'total_pending_suggestions' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function currentChallenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class, 'current_challenge_id');
    }
}
