<?php

declare(strict_types=1);

namespace App\Localization;

use App\Models\Environment;

/**
 * Server-side renderer for the bundled localization catalog. Used by the
 * Account Portal Inertia pages (which render server-side and can't rely
 * on the SDK to do the merge / interpolation).
 *
 * Resolution order for a key:
 *   1. operator override for $locale
 *   2. canonical default for $locale
 *   3. operator override for fallback_locale
 *   4. canonical default for fallback_locale
 *   5. the dot-keyed key itself (last-ditch — should never be hit unless the
 *      caller mistyped a key)
 *
 * Placeholder substitution is a simple `{name}` → value scan. ICU plural /
 * select rules are *not* implemented here; callers that need them should
 * branch in PHP and call `localize()` per-branch.
 */
final class Localizer
{
    /**
     * @param  array<string, string|int|float>  $placeholders
     */
    public function localize(
        Environment $environment,
        string $locale,
        string $key,
        array $placeholders = [],
    ): string {
        $localization = is_array($environment->localization) ? $environment->localization : [];
        $fallback = (string) ($localization['fallback_locale'] ?? CanonicalSchema::FALLBACK_LOCALE);
        $overrides = is_array($localization['overrides'] ?? null) ? $localization['overrides'] : [];

        $template = $this->lookup($overrides, $locale, $key)
            ?? $this->canonicalLookup($locale, $key)
            ?? $this->lookup($overrides, $fallback, $key)
            ?? $this->canonicalLookup($fallback, $key)
            ?? $key;

        if ($placeholders === []) {
            return $template;
        }

        $pairs = [];
        foreach ($placeholders as $name => $value) {
            $pairs['{'.$name.'}'] = (string) $value;
        }

        return strtr($template, $pairs);
    }

    /**
     * Render the full merged catalog for `$locale`. AU-7's public
     * `/v1/localization/{locale}` endpoint serves this shape.
     *
     * @return array<string, string>
     */
    public function catalog(Environment $environment, string $locale): array
    {
        $localization = is_array($environment->localization) ? $environment->localization : [];
        $fallback = (string) ($localization['fallback_locale'] ?? CanonicalSchema::FALLBACK_LOCALE);
        $overrides = is_array($localization['overrides'] ?? null) ? $localization['overrides'] : [];

        $base = $this->canonicalCatalog($fallback);
        $fallbackOverrides = is_array($overrides[$fallback] ?? null) ? $overrides[$fallback] : [];
        foreach ($fallbackOverrides as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $base[$k] = $v;
            }
        }

        if ($locale !== $fallback) {
            $localeCanonical = $this->canonicalCatalog($locale);
            foreach ($localeCanonical as $k => $v) {
                $base[$k] = $v;
            }
            $localeOverrides = is_array($overrides[$locale] ?? null) ? $overrides[$locale] : [];
            foreach ($localeOverrides as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $base[$k] = $v;
                }
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function lookup(array $overrides, string $locale, string $key): ?string
    {
        $localeMap = $overrides[$locale] ?? null;
        if (! is_array($localeMap)) {
            return null;
        }
        $value = $localeMap[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function canonicalLookup(string $locale, string $key): ?string
    {
        $catalog = $this->canonicalCatalog($locale);

        return $catalog[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function canonicalCatalog(string $locale): array
    {
        if (! in_array($locale, CanonicalSchema::SHIPPED_LOCALES, true)) {
            return [];
        }

        return CanonicalSchema::catalog($locale);
    }
}
