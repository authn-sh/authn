<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Environment;

/**
 * Compares an `Origin` (or `Referer`) header against an environment's
 * `allowed_origins` list. Used by both FapiCors (to decide which origin
 * to echo on the response) and EnforceFapiOrigin (to decide whether to
 * 403 a state-changing request).
 *
 * Rules — same logic for both middlewares:
 *
 *   1. Exact match by default (scheme + host + port).
 *   2. Default ports are stripped on both sides before compare so
 *      `https://app.example.com` and `https://app.example.com:443`
 *      match regardless of which one was registered.
 *   3. In development environments only, the registered list may carry
 *      `http://localhost:*` or `http://127.0.0.1:*` patterns to match
 *      any port (PLAN §6.5).
 */
final class AllowedOrigins
{
    public static function matches(Environment $environment, string $origin): bool
    {
        $normalizedOrigin = self::normalize($origin);
        if ($normalizedOrigin === null) {
            return false;
        }

        $allowed = is_array($environment->allowed_origins) ? $environment->allowed_origins : [];
        $devMode = $environment->kind === Environment::KIND_DEVELOPMENT;

        foreach ($allowed as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if ($devMode && self::matchesDevPattern($entry, $normalizedOrigin)) {
                return true;
            }
            if (self::normalize($entry) === $normalizedOrigin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip default ports and lowercase the host so two equivalent forms
     * compare equal. Returns null on a malformed origin.
     */
    public static function normalize(string $origin): ?string
    {
        $parts = parse_url($origin);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($port !== null && self::isDefaultPort($scheme, $port)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port !== null ? ':'.$port : '');
    }

    /**
     * Match a wildcard pattern from the allow list (e.g. `http://localhost:*`).
     * Only valid in development environments. Pattern grammar:
     *   <scheme>://<host>[:<port>|:*]
     */
    private static function matchesDevPattern(string $pattern, string $normalizedOrigin): bool
    {
        // The `#` in the negated character class would terminate a `#`-delimited
        // regex prematurely; using `~` as the delimiter avoids escaping inline.
        if (! preg_match('~^(https?)://([^:/?\#]+)(?::(\d+|\*))?$~i', $pattern, $m)) {
            return false;
        }
        $scheme = strtolower($m[1]);
        $host = strtolower($m[2]);
        $portSpec = $m[3] ?? null;

        if (! preg_match('~^(https?)://([^:/?\#]+)(?::(\d+))?$~i', $normalizedOrigin, $om)) {
            return false;
        }
        if (strtolower($om[1]) !== $scheme) {
            return false;
        }
        if (strtolower($om[2]) !== $host) {
            return false;
        }
        if ($portSpec === '*') {
            return true;
        }
        $originPort = $om[3] ?? null;

        return $portSpec === $originPort;
    }

    private static function isDefaultPort(string $scheme, int|string $port): bool
    {
        $port = (int) $port;

        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }
}
