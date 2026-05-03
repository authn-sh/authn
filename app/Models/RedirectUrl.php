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
 * @property string $url
 */
class RedirectUrl extends Model
{
    use HasFactory;
    use HasPrefixedUlid;

    protected string $idPrefix = 'redir_';

    protected $fillable = [
        'environment_id',
        'url',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new EnvironmentScope);
    }
}
