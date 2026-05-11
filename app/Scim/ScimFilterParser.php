<?php

declare(strict_types=1);

namespace App\Scim;

/**
 * Tiny SCIM filter parser for the `eq` / `co` / `sw` operators on a single
 * attribute. Supports the subset RFC 7644 IdPs (Okta, Azure AD, Rippling,
 * Google Workspace) emit in practice for user-directory queries:
 *
 *   - `userName eq "alice@acme.example"`
 *   - `emails.value co "@acme.example"`
 *   - `userName sw "alice"`
 *   - `externalId eq "ext-123"`
 *   - `active eq true`
 *
 * Returns a `ScimFilter` value object or `null` when the expression is
 * empty / unparseable. Combined `and` / `or` expressions are intentionally
 * out of scope for v0.6 — IdPs that need them are documented as deferred.
 */
final class ScimFilterParser
{
    public function parse(?string $expression): ?ScimFilter
    {
        if (! is_string($expression) || trim($expression) === '') {
            return null;
        }
        $expression = trim($expression);

        if (preg_match('/^(?<attr>[a-zA-Z][a-zA-Z0-9_.]*)\s+(?<op>eq|co|sw)\s+(?<val>.+)$/i', $expression, $match)) {
            $value = $this->parseValue($match['val']);
            if ($value === null) {
                return null;
            }

            return new ScimFilter(
                attribute: $match['attr'],
                operator: strtolower($match['op']),
                value: $value,
            );
        }

        return null;
    }

    private function parseValue(string $raw): string|int|bool|null
    {
        $raw = trim($raw);
        if ($raw === 'true') {
            return true;
        }
        if ($raw === 'false') {
            return false;
        }
        if ($raw === 'null') {
            return null;
        }
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $raw, $m)) {
            return stripcslashes($m[1]);
        }
        if (preg_match('/^\d+$/', $raw)) {
            return (int) $raw;
        }

        return null;
    }
}
