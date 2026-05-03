<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Casts\PrefixedUlid;
use App\Support\Id;
use LogicException;

/**
 * Drop into any Eloquent model whose primary key is a prefixed ULID.
 *
 * Models declare `protected string $idPrefix = 'user_';` and this trait does
 * the rest: marks the key as non-incrementing string, generates a fresh id on
 * `creating` when none is set, and registers the PrefixedUlid cast on the id
 * column scoped to the model's prefix.
 */
trait HasPrefixedUlid
{
    public static function bootHasPrefixedUlid(): void
    {
        static::creating(function ($model): void {
            $key = $model->getKeyName();
            if (empty($model->getAttribute($key))) {
                $model->setAttribute($key, Id::generate($model->getIdPrefix()));
            }
        });
    }

    public function initializeHasPrefixedUlid(): void
    {
        $this->setKeyType('string');
        $this->setIncrementing(false);

        // Register the PrefixedUlid cast on the primary key, scoped to this
        // model's prefix. Done here (rather than via $casts) so subclasses
        // inherit the right prefix automatically.
        $this->mergeCasts([
            $this->getKeyName() => PrefixedUlid::class.':'.$this->getIdPrefix(),
        ]);
    }

    public function getIdPrefix(): string
    {
        if (! property_exists($this, 'idPrefix') || ! is_string($this->idPrefix) || $this->idPrefix === '') {
            throw new LogicException(static::class.' must declare a non-empty `protected string $idPrefix`.');
        }

        return $this->idPrefix;
    }
}
