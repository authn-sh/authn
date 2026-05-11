<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Per-environment passkey toggles read by the FAPI passkey controller, the
 * sign-in challenge resolver (AU-4), and the dashboard. Strict-semantic v0.3
 * MFA contract: `enabled` gates *new enrolment*; existing passkeys remain
 * usable for sign-in even when the toggle is off (callers — AU-4's strategy
 * resolver — enforce the distinction).
 *
 * Lives on `Environment.user_settings`:
 *   - `authentication_strategies.passkey.enabled` (bool, default `true`)
 *   - `multi_factor.passkey_counts_as_mfa` (bool, default `true`)
 */
final readonly class PasskeySettings
{
    public function __construct(
        public bool $enabled = true,
        public bool $passkeyCountsAsMfa = true,
    ) {}

    /**
     * @param  array<string, mixed>|null  $userSettings
     */
    public static function fromUserSettings(?array $userSettings): self
    {
        $userSettings = is_array($userSettings) ? $userSettings : [];
        $strategies = is_array($userSettings['authentication_strategies'] ?? null)
            ? $userSettings['authentication_strategies']
            : [];
        $passkey = is_array($strategies['passkey'] ?? null) ? $strategies['passkey'] : [];

        $multiFactor = is_array($userSettings['multi_factor'] ?? null) ? $userSettings['multi_factor'] : [];

        return new self(
            enabled: (bool) ($passkey['enabled'] ?? true),
            passkeyCountsAsMfa: (bool) ($multiFactor['passkey_counts_as_mfa'] ?? true),
        );
    }

    /**
     * @return array{authentication_strategies: array{passkey: array{enabled: bool}}, multi_factor: array{passkey_counts_as_mfa: bool}}
     */
    public function toArray(): array
    {
        return [
            'authentication_strategies' => [
                'passkey' => ['enabled' => $this->enabled],
            ],
            'multi_factor' => [
                'passkey_counts_as_mfa' => $this->passkeyCountsAsMfa,
            ],
        ];
    }
}
