<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Environment;

/**
 * Generates routing-mode-aware URLs for the four authn.sh surfaces:
 * BAPI, Dashboard, FAPI, and Account Portal.
 *
 * Reads its inputs from `config('authn.*')` so a request never picks up
 * unexpected env-var values mid-flight.
 */
final class Url
{
    public static function bapi(string $path = ''): string
    {
        return self::buildUrl((string) config('authn.bapi_host'), self::normalisePath(self::bapiPathPrefix(), $path));
    }

    public static function dashboard(string $path = ''): string
    {
        return self::buildUrl((string) config('authn.dashboard_host'), self::normalisePath(self::dashboardPathPrefix(), $path));
    }

    public static function fapi(Environment $environment, string $path = ''): string
    {
        $mode = (string) config('authn.routing_mode');
        $isAdmin = self::isAdminEnvironment($environment);

        if ($mode === 'subdomain') {
            // Admin: bare app host. Tenant: <routing_label>.<app_host>.
            $host = $isAdmin
                ? (string) config('authn.app_host')
                : ($environment->routing_label.'.'.config('authn.app_host'));

            return self::buildUrl($host, self::normalisePath('', $path));
        }

        // Path mode. Admin lives at the bare root; tenants under /<routing_label>.
        $prefix = $isAdmin ? '' : '/'.$environment->routing_label;

        return self::buildUrl((string) config('authn.app_host'), self::normalisePath($prefix, $path));
    }

    public static function accountPortal(Environment $environment, string $path = ''): string
    {
        $mode = (string) config('authn.routing_mode');
        $isAdmin = self::isAdminEnvironment($environment);

        if ($mode === 'subdomain') {
            // Account Portal shares the FAPI host; routes sit at the host root.
            $host = $isAdmin
                ? (string) config('authn.app_host')
                : ($environment->routing_label.'.'.config('authn.app_host'));

            return self::buildUrl($host, self::normalisePath('', $path));
        }

        // Path mode. Admin Account Portal pages live at the bare root;
        // tenants under /<routing_label>/account.
        if ($isAdmin) {
            return self::buildUrl((string) config('authn.app_host'), self::normalisePath('', $path));
        }

        return self::buildUrl((string) config('authn.app_host'), self::normalisePath('/'.$environment->routing_label.'/account', $path));
    }

    /**
     * Path prefix that BAPI routes are registered under. Empty string in
     * subdomain mode (the host alone identifies BAPI); `/api` in path mode.
     */
    public static function bapiPathPrefix(): string
    {
        return config('authn.routing_mode') === 'path' ? '/api' : '';
    }

    public static function dashboardPathPrefix(): string
    {
        return config('authn.routing_mode') === 'path' ? '/dashboard' : '';
    }

    private static function isAdminEnvironment(Environment $environment): bool
    {
        // The reserved `_admin` env never carries a routing_label — that's the
        // unambiguous signal it should be served at the bare host. Falling back
        // to a project lookup would force a DB roundtrip every URL build.
        return $environment->routing_label === null;
    }

    private static function buildUrl(string $host, string $path): string
    {
        $scheme = (string) config('authn.app_scheme', 'https');
        $portSuffix = (string) config('authn.app_port_suffix', '');

        return $scheme.'://'.$host.$portSuffix.$path;
    }

    private static function normalisePath(string $prefix, string $path): string
    {
        $path = '/'.ltrim($path, '/');
        if ($path === '/') {
            $path = '';
        }

        $prefix = rtrim($prefix, '/');

        return $prefix.$path;
    }
}
