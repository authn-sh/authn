<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property string $kind development | staging | production
 * @property string $slug operator-facing label, unique per project
 * @property ?string $routing_label opaque routing identity, globally unique
 * @property-read string $frontend_api_host derived: routing_label + app_host
 * @property bool $is_satellite
 * @property array $allowed_origins
 * @property array $appearance
 * @property array $localization
 * @property-read string $appearance_etag
 * @property-read string $localization_override_etag
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
        'routing_label',
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

    /**
     * Default appearance blob. Mirrored by the column default — kept here so
     * the seeder, observer, and BAPI controller agree on the empty shape.
     *
     * @return array{variables: array<string,string>, elements: array<string,string>, layout: array<string,mixed>}
     */
    public static function defaultAppearance(): array
    {
        return [
            'variables' => [],
            'elements' => [],
            'layout' => [],
        ];
    }

    /**
     * Default localization blob. Mirrored by the column default — kept in
     * code so the observer + reset-to-defaults path agree on the canonical
     * shape.
     *
     * @return array{default_locale: string, fallback_locale: string, supported_locales: list<string>, overrides: array<string, array<string, string>>}
     */
    public static function defaultLocalization(): array
    {
        return [
            'default_locale' => 'en-US',
            'fallback_locale' => 'en-US',
            'supported_locales' => ['en-US', 'pt-BR', 'es-ES', 'fr-FR', 'de-DE'],
            'overrides' => [],
        ];
    }

    /**
     * Stable etag for the current `appearance` blob. SDKs use it as a
     * cheap "did the appearance change since last render?" gate.
     */
    protected function appearanceEtag(): Attribute
    {
        return Attribute::get(function (): string {
            $blob = is_array($this->appearance) ? $this->appearance : [];
            $canonical = json_encode(
                $blob,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            return 'sha256:'.hash('sha256', (string) $canonical);
        });
    }

    /**
     * Stable etag for the `overrides` sub-blob. The public localization
     * catalog endpoint returns this as the response `ETag` so SDKs can
     * skip re-fetching when nothing changed.
     */
    protected function localizationOverrideEtag(): Attribute
    {
        return Attribute::get(function (): string {
            $localization = is_array($this->localization) ? $this->localization : [];
            $overrides = is_array($localization['overrides'] ?? null) ? $localization['overrides'] : [];
            $canonical = json_encode(
                $overrides,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            return 'sha256:'.hash('sha256', (string) $canonical);
        });
    }

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

            $appearance = is_array($env->appearance) ? $env->appearance : [];
            if ($appearance === []) {
                $env->appearance = self::defaultAppearance();
            }

            $localization = is_array($env->localization) ? $env->localization : [];
            if ($localization === []) {
                $env->localization = self::defaultLocalization();
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

    /**
     * Derived FAPI host. The reserved `_admin` env (no `routing_label`) lives
     * at the bare app host; everything else is `<routing_label>.<app_host>`
     * in subdomain mode and `<app_host>` (with the label as path prefix) in
     * path mode. The path-mode form just returns the bare host because there
     * is no host-level partitioning — `Url::fapi()` adds the prefix.
     */
    protected function frontendApiHost(): Attribute
    {
        return Attribute::get(function (): string {
            $appHost = (string) config('authn.app_host', 'localhost');
            if ($this->routing_label === null) {
                return $appHost;
            }
            if ((string) config('authn.routing_mode') === 'subdomain') {
                return $this->routing_label.'.'.$appHost;
            }

            return $appHost;
        });
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
