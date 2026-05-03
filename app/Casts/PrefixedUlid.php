<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Id;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<string, string>
 */
final class PrefixedUlid implements CastsAttributes
{
    /**
     * The prefix this column expects (e.g. `'user_'`). Passed via the cast
     * argument syntax: `protected $casts = ['id' => PrefixedUlid::class.':user_'];`
     */
    public function __construct(private readonly ?string $prefix = null) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Permissive read: surface whatever's in storage, but defensively
        // refuse to return anything that isn't a syntactically valid id.
        if (! is_string($value) || ! Id::isValid($value)) {
            throw new InvalidArgumentException(sprintf(
                'Column %s on %s holds an invalid prefixed id: %s',
                $key,
                $model::class,
                var_export($value, true),
            ));
        }

        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Column %s on %s expects a string id, got %s.',
                $key,
                $model::class,
                get_debug_type($value),
            ));
        }

        ['prefix' => $prefix] = Id::parse($value);

        if ($this->prefix !== null && $prefix !== $this->prefix) {
            throw new InvalidArgumentException(sprintf(
                'Column %s on %s expects prefix "%s", got "%s" (id: %s).',
                $key,
                $model::class,
                $this->prefix,
                $prefix,
                $value,
            ));
        }

        return $value;
    }
}
