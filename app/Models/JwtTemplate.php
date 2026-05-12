<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Database\Factories\JwtTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * v0.7 named JWT template. Per-environment, looked up by `name` from
 * Session::getToken({template}). `claims` is a JSON object with Liquid-style
 * placeholders rendered against the User / Session / Organization snapshot
 * by JwtTemplateRenderer (AU-3). `custom_signing_key` is an optional
 * escape-hatch PEM that overrides the env's default SigningKey when set.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $name
 * @property array<string, mixed> $claims
 * @property int $lifetime
 * @property int $allowed_clock_skew
 * @property string $signing_algorithm
 * @property ?string $custom_signing_key
 * @property ?\DateTimeInterface $removed_at
 */
class JwtTemplate extends Model
{
    use HasFactory;
    use HasPrefixedUlid;
    use SoftDeletes;

    public const ALG_RS256 = 'RS256';

    public const ALG_ES256 = 'ES256';

    public const ALG_HS256 = 'HS256';

    public const ALGORITHMS = [
        self::ALG_RS256,
        self::ALG_ES256,
        self::ALG_HS256,
    ];

    public const DELETED_AT = 'removed_at';

    protected string $idPrefix = 'jtmpl_';

    protected $fillable = [
        'environment_id',
        'name',
        'claims',
        'lifetime',
        'allowed_clock_skew',
        'signing_algorithm',
        'custom_signing_key',
    ];

    protected $hidden = [
        'custom_signing_key',
    ];

    protected function casts(): array
    {
        return [
            'claims' => 'array',
            'lifetime' => 'integer',
            'allowed_clock_skew' => 'integer',
            'custom_signing_key' => 'encrypted',
            'removed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    protected static function newFactory(): JwtTemplateFactory
    {
        return JwtTemplateFactory::new();
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
