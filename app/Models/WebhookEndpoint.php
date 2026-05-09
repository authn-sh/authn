<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-env webhook destination. Signing secret is rotated by replacing
 * `signing_secret` and shifting the prior value into
 * `prior_signing_secret`; both validate during the rotation window so
 * customers can swap without missing events.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $url
 * @property string $signing_secret
 * @property ?string $prior_signing_secret
 * @property ?\DateTimeInterface $prior_signing_secret_expires_at
 * @property array $enabled_event_types
 * @property bool $enabled
 * @property ?\DateTimeInterface $disabled_at
 */
class WebhookEndpoint extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const SECRET_DISPLAY_PREFIX = 'whsec_';

    public const ROTATION_WINDOW_SECONDS = 24 * 60 * 60;

    public const AUTO_DISABLE_AFTER_DAYS = 7;

    protected string $idPrefix = 'whe_';

    protected $fillable = [
        'environment_id',
        'url',
        'signing_secret',
        'prior_signing_secret',
        'prior_signing_secret_expires_at',
        'enabled_event_types',
        'enabled',
        'disabled_at',
    ];

    protected $hidden = [
        'signing_secret',
        'prior_signing_secret',
    ];

    protected function casts(): array
    {
        return [
            'enabled_event_types' => 'array',
            'enabled' => 'boolean',
            'prior_signing_secret_expires_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
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

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function matches(string $type): bool
    {
        if (! $this->enabled) {
            return false;
        }
        $list = is_array($this->enabled_event_types) ? $this->enabled_event_types : [];
        if (in_array('*', $list, true) || in_array($type, $list, true)) {
            return true;
        }
        foreach ($list as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }
            // Prefix glob: `organization.*` matches `organization.created`,
            // `organization.updated`, etc. Trailing `*` is the only glob
            // form supported — anything else is treated as a literal.
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1); // keep the trailing dot
                if (str_starts_with($type, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Mask the secret for display in list views — full secret is only
     * surfaced on create + rotate.
     */
    public function secretPrefix(): string
    {
        return self::SECRET_DISPLAY_PREFIX.substr((string) $this->signing_secret, 0, 4).'…';
    }

    public function displaySecret(): string
    {
        return self::SECRET_DISPLAY_PREFIX.$this->signing_secret;
    }

    public static function mintSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
