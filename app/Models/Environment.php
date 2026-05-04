<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property string $kind development | staging | production
 * @property string $frontend_api_host
 * @property bool $is_satellite
 * @property array $allowed_origins
 * @property array $appearance
 * @property array $localization
 */
class Environment extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const KIND_DEVELOPMENT = 'development';

    public const KIND_STAGING = 'staging';

    public const KIND_PRODUCTION = 'production';

    public const KINDS = [
        self::KIND_DEVELOPMENT,
        self::KIND_STAGING,
        self::KIND_PRODUCTION,
    ];

    public const SIGNUP_MODE_PUBLIC = 'public';

    public const SIGNUP_MODE_RESTRICTED = 'restricted';

    public const SIGNUP_MODES = [
        self::SIGNUP_MODE_PUBLIC,
        self::SIGNUP_MODE_RESTRICTED,
    ];

    protected string $idPrefix = 'env_';

    protected $fillable = [
        'project_id',
        'kind',
        'slug',
        'frontend_api_host',
        'dashboard_url',
        'home_url',
        'is_satellite',
        'proxy_url',
        'allowed_origins',
        'appearance',
        'localization',
        'user_settings',
        'signup_mode',
    ];

    protected function casts(): array
    {
        return [
            'is_satellite' => 'boolean',
            'allowed_origins' => 'array',
            'appearance' => 'array',
            'localization' => 'array',
            'user_settings' => 'array',
        ];
    }

    public const TEST_MODE_ENABLED = 'enabled';

    public const TEST_MODE_DISABLED = 'disabled';

    public const TEST_MODE_REJECTED = 'rejected';

    public const TEST_MODES = [self::TEST_MODE_ENABLED, self::TEST_MODE_DISABLED, self::TEST_MODE_REJECTED];

    protected static function booted(): void
    {
        static::creating(function (self $env): void {
            $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
            if (! array_key_exists('test_mode', $userSettings)) {
                // Production envs default to `rejected` so a CI test against a
                // reserved identifier in prod fails loudly rather than silently
                // creating a test user. Dev / staging default to `enabled`.
                $userSettings['test_mode'] = $env->kind === self::KIND_PRODUCTION
                    ? self::TEST_MODE_REJECTED
                    : self::TEST_MODE_ENABLED;
                $env->user_settings = $userSettings;
            }
        });
    }

    public function testMode(): string
    {
        $userSettings = is_array($this->user_settings) ? $this->user_settings : [];
        $value = $userSettings['test_mode'] ?? null;

        return in_array($value, self::TEST_MODES, true)
            ? (string) $value
            : self::TEST_MODE_DISABLED;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function signingKeys(): HasMany
    {
        return $this->hasMany(SigningKey::class);
    }

    public function isProduction(): bool
    {
        return $this->kind === self::KIND_PRODUCTION;
    }

    /**
     * The `_test` / `_live` segment used in API key prefixes.
     */
    public function keyEnvironmentSegment(): string
    {
        return $this->isProduction() ? 'live' : 'test';
    }
}
