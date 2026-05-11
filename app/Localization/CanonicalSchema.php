<?php

declare(strict_types=1);

namespace App\Localization;

/**
 * Read-only view of the en-US canonical key set. AU-7's BAPI validator
 * delegates to this so adding / renaming a key is a one-file change in
 * `Defaults/en-US.php` and every override map is re-validated against the
 * new shape automatically.
 */
final class CanonicalSchema
{
    /**
     * v0.5 ships canonical catalogs for these BCP-47 tags. Operators may
     * surface additional locales by listing them in `supported_locales`
     * and supplying a full override map.
     *
     * @var list<string>
     */
    public const SHIPPED_LOCALES = ['en-US', 'pt-BR', 'es-ES', 'fr-FR', 'de-DE'];

    public const FALLBACK_LOCALE = 'en-US';

    /** @var array<string, string>|null */
    private static ?array $canonicalCache = null;

    /**
     * Per-locale catalog cache. Keys are BCP-47 tags; values are flat
     * dot-keyed string maps.
     *
     * @var array<string, array<string, string>>
     */
    private static array $localeCache = [];

    /**
     * Flat dot-keyed map for en-US. Source of truth for `keys()`.
     *
     * @return array<string, string>
     */
    public static function canonical(): array
    {
        return self::$canonicalCache ??= self::loadFile(self::FALLBACK_LOCALE);
    }

    /**
     * Sorted list of canonical keys (AU-7's validator's allow-list).
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = array_keys(self::canonical());
        sort($keys);

        return $keys;
    }

    /**
     * Catalog for one of the shipped locales. Throws if asked for a
     * locale that doesn't ship with the server.
     *
     * @return array<string, string>
     */
    public static function catalog(string $locale): array
    {
        if (! in_array($locale, self::SHIPPED_LOCALES, true)) {
            throw new \InvalidArgumentException("Locale {$locale} is not bundled with the server.");
        }

        return self::$localeCache[$locale] ??= self::loadFile($locale);
    }

    /**
     * ICU-style `{name}` placeholders in the en-US value for `$key`. The
     * BAPI validator surfaces a `Warning: 299 missing-placeholder` header
     * when an override drops one of these.
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $key): array
    {
        $canonical = self::canonical();
        if (! isset($canonical[$key])) {
            return [];
        }

        return self::placeholdersInString($canonical[$key]);
    }

    /**
     * @return list<string>
     */
    public static function placeholdersInString(string $template): array
    {
        if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $template, $matches) === false) {
            return [];
        }

        $tokens = $matches[1] ?? [];
        $tokens = array_values(array_unique($tokens));

        return array_values(array_filter($tokens, 'is_string'));
    }

    /**
     * @return array<string, string>
     */
    private static function loadFile(string $locale): array
    {
        $path = __DIR__.'/Defaults/'.$locale.'.php';
        if (! is_file($path)) {
            throw new \RuntimeException("Localization default file missing for {$locale} at {$path}.");
        }

        $loaded = require $path;
        if (! is_array($loaded)) {
            throw new \RuntimeException("Localization file {$path} did not return an array.");
        }

        $out = [];
        foreach ($loaded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
