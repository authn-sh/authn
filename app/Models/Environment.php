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
