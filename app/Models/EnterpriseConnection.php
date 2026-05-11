<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Database\Factories\EnterpriseConnectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * v0.6 enterprise IdP connection. Single shape carries both SAML and OIDC
 * (`protocol` discriminator). Instance-wide when `organization_id` is null;
 * org-scoped otherwise. `domains[]` is the list of verified domains routed
 * to this connection for domain-based sign-in dispatch.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $protocol
 * @property string $name
 * @property bool $enabled
 * @property ?string $organization_id
 * @property array<int, string> $domains
 * @property ?string $default_role
 * @property array<string, mixed> $attribute_mapping
 * @property ?string $saml_idp_entity_id
 * @property ?string $saml_sso_url
 * @property ?string $saml_idp_certificate
 * @property ?string $saml_signing_algorithm
 * @property ?string $saml_audience_uri
 * @property ?string $saml_signing_key
 * @property ?string $oidc_issuer
 * @property ?string $oidc_discovery_endpoint
 * @property ?string $oidc_client_id
 * @property ?string $oidc_client_secret
 * @property array<int, string> $oidc_scopes
 * @property ?\DateTimeInterface $removed_at
 */
class EnterpriseConnection extends Model
{
    use HasFactory;
    use HasPrefixedUlid;
    use SoftDeletes;

    public const PROTOCOL_SAML = 'saml';

    public const PROTOCOL_OIDC = 'oidc';

    public const PROTOCOLS = [
        self::PROTOCOL_SAML,
        self::PROTOCOL_OIDC,
    ];

    public const DELETED_AT = 'removed_at';

    protected string $idPrefix = 'entcon_';

    protected $fillable = [
        'environment_id',
        'protocol',
        'name',
        'enabled',
        'organization_id',
        'domains',
        'default_role',
        'attribute_mapping',
        'saml_idp_entity_id',
        'saml_sso_url',
        'saml_idp_certificate',
        'saml_signing_algorithm',
        'saml_audience_uri',
        'saml_signing_key',
        'oidc_issuer',
        'oidc_discovery_endpoint',
        'oidc_client_id',
        'oidc_client_secret',
        'oidc_scopes',
    ];

    protected $hidden = [
        'saml_signing_key',
        'oidc_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'domains' => 'array',
            'attribute_mapping' => 'array',
            'oidc_scopes' => 'array',
            'saml_signing_key' => 'encrypted',
            'oidc_client_secret' => 'encrypted',
            'removed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }

    protected static function newFactory(): EnterpriseConnectionFactory
    {
        return EnterpriseConnectionFactory::new();
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function enterpriseAccounts(): HasMany
    {
        return $this->hasMany(EnterpriseAccount::class);
    }

    public function isSaml(): bool
    {
        return $this->protocol === self::PROTOCOL_SAML;
    }

    public function isOidc(): bool
    {
        return $this->protocol === self::PROTOCOL_OIDC;
    }

    /**
     * @param  Builder<EnterpriseConnection>  $query
     * @return Builder<EnterpriseConnection>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * @param  Builder<EnterpriseConnection>  $query
     * @return Builder<EnterpriseConnection>
     */
    public function scopeInstanceWide(Builder $query): Builder
    {
        return $query->whereNull('organization_id');
    }
}
