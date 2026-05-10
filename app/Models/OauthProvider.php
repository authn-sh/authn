<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use App\Support\Url;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-environment OAuth/OIDC connection. One row drives one
 * `oauth_<provider_key>` strategy, identifiable in the `Verification.strategy`
 * enum via the regex carve-out on `Verification::isValidStrategy()`.
 *
 * `provider_kind` is one of:
 *   - `preset`        — first-party preset (google, github, apple, microsoft);
 *                       endpoints + default scopes baked into AU-4's registry.
 *   - `custom_oidc`   — issuer URL + auto-fetched discovery; cached 5 minutes.
 *   - `custom_oauth2` — admin-supplied authorization / token / userinfo.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $provider_kind
 * @property string $provider_key
 * @property string $name
 * @property bool $enabled
 * @property bool $allow_sign_in
 * @property bool $allow_sign_up
 * @property bool $block_email_subaddresses
 * @property string $client_id
 * @property string $encrypted_client_secret
 * @property array<int, string> $scopes
 * @property array<string, mixed> $additional_authorization_params
 * @property array<string, mixed> $attribute_mapping
 * @property ?string $issuer
 * @property ?string $discovery_endpoint
 * @property ?\DateTimeInterface $discovery_cached_at
 * @property ?string $authorization_endpoint
 * @property ?string $token_endpoint
 * @property ?string $userinfo_endpoint
 * @property ?string $jwks_uri
 * @property ?array<int, string> $id_token_signing_algs
 * @property ?string $userinfo_method
 * @property ?string $userinfo_auth
 * @property-read string $redirect_uri
 */
class OauthProvider extends Model
{
    use HasFactory;
    use HasPrefixedUlid;
    use SoftDeletes;

    public const KIND_PRESET = 'preset';

    public const KIND_CUSTOM_OIDC = 'custom_oidc';

    public const KIND_CUSTOM_OAUTH2 = 'custom_oauth2';

    public const KINDS = [
        self::KIND_PRESET,
        self::KIND_CUSTOM_OIDC,
        self::KIND_CUSTOM_OAUTH2,
    ];

    public const USERINFO_METHOD_GET = 'GET';

    public const USERINFO_METHOD_POST = 'POST';

    public const USERINFO_AUTH_BEARER = 'bearer';

    public const USERINFO_AUTH_BASIC = 'basic';

    public const USERINFO_AUTH_QUERY = 'query';

    protected string $idPrefix = 'oauthp_';

    protected $fillable = [
        'environment_id',
        'provider_kind',
        'provider_key',
        'name',
        'enabled',
        'allow_sign_in',
        'allow_sign_up',
        'block_email_subaddresses',
        'client_id',
        'encrypted_client_secret',
        'scopes',
        'additional_authorization_params',
        'attribute_mapping',
        'issuer',
        'discovery_endpoint',
        'discovery_cached_at',
        'authorization_endpoint',
        'token_endpoint',
        'userinfo_endpoint',
        'jwks_uri',
        'id_token_signing_algs',
        'userinfo_method',
        'userinfo_auth',
    ];

    protected $hidden = [
        'encrypted_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allow_sign_in' => 'boolean',
            'allow_sign_up' => 'boolean',
            'block_email_subaddresses' => 'boolean',
            'encrypted_client_secret' => 'encrypted',
            'scopes' => 'array',
            'additional_authorization_params' => 'array',
            'attribute_mapping' => 'array',
            'id_token_signing_algs' => 'array',
            'discovery_cached_at' => 'immutable_datetime',
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

    public function externalAccounts(): HasMany
    {
        return $this->hasMany(ExternalAccount::class);
    }

    /**
     * The canonical callback URL the IdP redirects back to. Format is
     * stable (env's FAPI host + `/v1/oauth-callback/<provider_key>`) so the
     * dashboard can copy-paste it into the provider's allow-list at create
     * time. AU-6 mounts the matching route.
     */
    protected function redirectUri(): Attribute
    {
        return Attribute::get(function (): string {
            return Url::fapi($this->environment, '/v1/oauth-callback/'.$this->provider_key);
        });
    }
}
