<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generate and parse prefixed-ULID identifiers.
 *
 * Format: `{prefix}{ulid}` where `prefix` is one of the values in IdPrefix
 * (always trailing-underscore-terminated, e.g. `'user_'`) and `ulid` is the
 * 26-character Crockford-base32 ULID returned by Laravel's Str::ulid().
 */
final class Id
{
    /**
     * The 26-character Crockford-base32 alphabet used by ULIDs.
     */
    private const ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /**
     * Mint a fresh prefixed ULID.
     *
     * @throws InvalidArgumentException when $prefix is not registered.
     */
    public static function generate(string $prefix): string
    {
        if (! IdPrefix::isRegistered($prefix)) {
            throw new InvalidArgumentException("Unknown id prefix: {$prefix}");
        }

        return $prefix.Str::ulid()->toBase32();
    }

    /**
     * Split a prefixed id into its prefix and ULID parts.
     *
     * @return array{prefix: string, ulid: string}
     *
     * @throws InvalidArgumentException when $id is malformed or the prefix is unregistered.
     */
    public static function parse(string $id): array
    {
        $underscore = strrpos($id, '_');
        if ($underscore === false) {
            throw new InvalidArgumentException("Malformed id (no prefix): {$id}");
        }

        $prefix = substr($id, 0, $underscore + 1);
        $ulid = substr($id, $underscore + 1);

        if (! IdPrefix::isRegistered($prefix)) {
            throw new InvalidArgumentException("Unknown id prefix: {$prefix}");
        }

        if (preg_match(self::ULID_PATTERN, strtoupper($ulid)) !== 1) {
            throw new InvalidArgumentException("Malformed ULID portion: {$ulid}");
        }

        return ['prefix' => $prefix, 'ulid' => strtoupper($ulid)];
    }

    /**
     * Whether $id is a syntactically valid prefixed ULID with a registered prefix.
     */
    public static function isValid(string $id): bool
    {
        try {
            self::parse($id);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
