<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasPrefixedUlid;
use App\Database\Scopes\EnvironmentScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $environment_id
 * @property string $identifier Email or @domain
 * @property string $identifier_type email_address | email_domain
 */
class AllowlistIdentifier extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    public const TYPE_EMAIL_ADDRESS = 'email_address';

    public const TYPE_EMAIL_DOMAIN = 'email_domain';

    protected string $idPrefix = 'allow_';

    protected $fillable = [
        'environment_id',
        'identifier',
        'identifier_type',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }
}
