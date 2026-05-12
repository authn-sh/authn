<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\Browser;
use RuntimeException;

/**
 * Chrome DevTools Protocol shim for the WebAuthn virtual authenticator.
 *
 * Selenium's W3C WebAuthn extension is supported in Chromium since
 * Chrome 99 (the standalone-chromium image bundles a current version),
 * but the underlying CDP commands are what we drive here for maximum
 * portability.  We enable the `WebAuthn` domain, register a single
 * platform authenticator backed by user-verified internal transports
 * (matches authn.sh's PLAN §6.2 RP policy of platform + cross-platform
 * with verified UV), and expose helpers to introspect / clean up the
 * registered credentials per test.
 */
final class VirtualAuthenticator
{
    private const SESSION_KEY = '__authn_virtual_authenticator_id';

    public static function install(Browser $browser): string
    {
        $driver = $browser->driver;

        self::executeCdp($driver, 'WebAuthn.enable', ['enableUI' => false]);
        $result = self::executeCdp($driver, 'WebAuthn.addVirtualAuthenticator', [
            'options' => [
                'protocol' => 'ctap2',
                'transport' => 'internal',
                'hasResidentKey' => true,
                'hasUserVerification' => true,
                'isUserVerified' => true,
                'automaticPresenceSimulation' => true,
            ],
        ]);

        $authenticatorId = $result['authenticatorId'] ?? null;
        if (! is_string($authenticatorId) || $authenticatorId === '') {
            throw new RuntimeException('Failed to register virtual WebAuthn authenticator via CDP.');
        }

        $browser->{self::SESSION_KEY} = $authenticatorId;

        return $authenticatorId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function credentials(Browser $browser): array
    {
        $authenticatorId = $browser->{self::SESSION_KEY} ?? null;
        if (! is_string($authenticatorId)) {
            return [];
        }

        $result = self::executeCdp($browser->driver, 'WebAuthn.getCredentials', [
            'authenticatorId' => $authenticatorId,
        ]);

        /** @var list<array<string, mixed>> $credentials */
        $credentials = $result['credentials'] ?? [];

        return $credentials;
    }

    public static function remove(Browser $browser): void
    {
        $authenticatorId = $browser->{self::SESSION_KEY} ?? null;
        if (! is_string($authenticatorId) || $authenticatorId === '') {
            return;
        }

        try {
            self::executeCdp($browser->driver, 'WebAuthn.removeVirtualAuthenticator', [
                'authenticatorId' => $authenticatorId,
            ]);
            self::executeCdp($browser->driver, 'WebAuthn.disable', []);
        } finally {
            $browser->{self::SESSION_KEY} = null;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function executeCdp(RemoteWebDriver $driver, string $cmd, array $params): array
    {
        $executor = $driver->getCommandExecutor();
        $sessionId = $driver->getSessionID();
        $url = $executor->getAddressOfRemoteServer().'/session/'.$sessionId.'/goog/cdp/execute';

        $payload = json_encode(['cmd' => $cmd, 'params' => $params], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed for CDP request');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("CDP request failed: {$err}");
        }
        if ($status >= 400) {
            throw new RuntimeException("CDP {$cmd} returned HTTP {$status}: {$body}");
        }

        /** @var array{value?: array<string, mixed>} $decoded */
        $decoded = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);

        return $decoded['value'] ?? [];
    }
}
