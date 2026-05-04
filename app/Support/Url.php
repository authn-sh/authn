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
        if ($mode === 'subdomain') {
            return self::buildUrl($environment->frontend_api_host, self::normalisePath('', $path));
        }

        // The `_admin` env is mounted at the bare root in path mode, so it
        // does not carry the `/{env_slug}` prefix that tenant envs do.
        $prefix = self::isAdminEnvironment($environment) ? '' : '/'.$environment->slug;

        return self::buildUrl((string) config('authn.app_host'), self::normalisePath($prefix, $path));
    }

    public static function accountPortal(Environment $environment, string $path = ''): string
    {
        $mode = (string) config('authn.routing_mode');
        if ($mode === 'subdomain') {
            // Account Portal shares the FAPI host; routes are at the host root, not /v1.
            return self::buildUrl($environment->frontend_api_host, self::normalisePath('', $path));
        }

        // Admin Account Portal pages (sign-in, sign-up, user, verify, sign-out)
        // live at the bare root, not under `/_admin/account`.
        if (self::isAdminEnvironment($environment)) {
            return self::buildUrl((string) config('authn.app_host'), self::normalisePath('', $path));
        }

        return self::buildUrl((string) config('authn.app_host'), self::normalisePath('/'.$environment->slug.'/account', $path));
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
        $project = $environment->relationLoaded('project') ? $environment->project : $environment->project()->first();

        return $project !== null && (bool) $project->is_admin_project;
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
