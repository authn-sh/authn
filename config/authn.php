<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| authn.sh configuration
|--------------------------------------------------------------------------
|
| Per-installation knobs that don't fit elsewhere. Values are read from env
| at boot and computed once into the config repository — request-time code
| reads from `config('authn.*')`, never directly from `env()`.
|
| See PLAN §6 for the full routing-mode discussion.
*/

$rawAppUrl = env('AUTHN_APP_URL', env('APP_URL', 'http://localhost'));
$appHost = parse_url((string) $rawAppUrl, PHP_URL_HOST) ?: 'localhost';
$scheme = parse_url((string) $rawAppUrl, PHP_URL_SCHEME) ?: 'https';
$port = parse_url((string) $rawAppUrl, PHP_URL_PORT);
$portSuffix = $port !== null ? ':'.$port : '';

$routingMode = env('AUTHN_ROUTING_MODE', 'subdomain');
if (! in_array($routingMode, ['subdomain', 'path'], true)) {
    $routingMode = 'subdomain';
}

return [

    /*
    |----------------------------------------------------------------------
    | Routing mode
    |----------------------------------------------------------------------
    |
    | `subdomain` (default for hosted authn.sh)
    |   Each surface lives on its own host:
    |     api.{app_host}             — BAPI
    |     dashboard.{app_host}       — Dashboard
    |     {env_slug}.{app_host}      — FAPI + Account Portal
    |   Requires wildcard DNS + wildcard TLS (PLAN §17.3).
    |
    | `path` (recommended self-host default)
    |   Everything lives on a single host, distinguished by URL prefix:
    |     {app_host}/api/v1/...      — BAPI
    |     {app_host}/dashboard/...   — Dashboard
    |     {app_host}/{env_slug}/v1   — FAPI
    |     {app_host}/{env_slug}/account — Account Portal
    |   No wildcard DNS needed.
    */

    'routing_mode' => $routingMode,

    /*
    |----------------------------------------------------------------------
    | Application URL host
    |----------------------------------------------------------------------
    |
    | The canonical hostname of this authn.sh installation. Subdomain mode
    | uses this as the base of every generated URL; path mode uses it as
    | the single host. Pulled from AUTHN_APP_URL (falling back to APP_URL).
    */

    'app_host' => $appHost,
    'app_scheme' => $scheme,
    'app_port_suffix' => $portSuffix,

    /*
    |----------------------------------------------------------------------
    | Derived hostnames
    |----------------------------------------------------------------------
    |
    | These are the values code uses at routing dispatch time. Centralised
    | here so the dispatcher and the URL helpers (App\Support\Url) agree.
    */

    'bapi_host' => $routingMode === 'subdomain' ? 'api.'.$appHost : $appHost,
    'dashboard_host' => $routingMode === 'subdomain' ? 'dashboard.'.$appHost : $appHost,

    /*
    |----------------------------------------------------------------------
    | Reserved env-slug labels
    |----------------------------------------------------------------------
    |
    | Labels that customers cannot use as their environment slug because
    | they collide with a system surface or a non-tenant subdomain. Routing
    | dispatch refuses them up front so we don't even hit the DB.
    |
    | `_admin` is the legitimate slug of the system project's environment;
    | it is NOT in this list — it resolves like any other env slug.
    */

    'reserved_env_slugs' => [
        'api', 'dashboard', 'docs', 'site', 'www', 'mail', 'admin',
        'status', 'help', 'support', 'blog', 'cdn', 'static', 'assets',
        'horizon', 'health', 'up',
    ],

];
