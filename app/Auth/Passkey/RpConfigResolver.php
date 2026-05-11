<?php

declare(strict_types=1);

namespace App\Auth\Passkey;

use App\Models\Environment;
use Webauthn\PublicKeyCredentialRpEntity;

/**
 * Per-environment WebAuthn relying-party config:
 *   - `rp.id` is the FAPI host (`<env_slug>.authn.sh` in subdomain mode,
 *     the bare app host in path mode).
 *   - `rp.name` is the operator's application name (falls back to the env
 *     slug).
 *   - The allowed-origin list is the FAPI host's URL plus every entry in
 *     `Environment.allowed_origins`. WebAuthn requires HTTPS in
 *     production; we mirror `authn.app_scheme` so dev installs on
 *     `http://localhost` keep working.
 */
final class RpConfigResolver
{
    public function rpEntity(Environment $environment): PublicKeyCredentialRpEntity
    {
        $appearance = is_array($environment->appearance) ? $environment->appearance : [];
        $name = is_string($appearance['application_name'] ?? null) && $appearance['application_name'] !== ''
            ? $appearance['application_name']
            : ((string) (config('app.name') ?? $environment->slug));

        return PublicKeyCredentialRpEntity::create($name, $environment->frontend_api_host);
    }

    public function rpId(Environment $environment): string
    {
        return $environment->frontend_api_host;
    }

    /**
     * Origins WebAuthn responses are allowed to come from. Always includes
     * the env's FAPI URL; operators add more via `Environment.allowed_origins`
     * (the same list used for CORS).
     *
     * @return list<string>
     */
    public function allowedOrigins(Environment $environment): array
    {
        $scheme = (string) (config('authn.app_scheme') ?? 'https');
        $host = $environment->frontend_api_host;
        $port = (string) (config('authn.app_port_suffix') ?? '');
        $primary = $scheme.'://'.$host.$port;

        $extra = is_array($environment->allowed_origins) ? $environment->allowed_origins : [];
        $extra = array_values(array_filter(array_map('strval', $extra), static fn ($o) => $o !== ''));

        return array_values(array_unique([$primary, ...$extra]));
    }
}
