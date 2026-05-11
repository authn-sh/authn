<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Per-environment Enterprise SSO toggles. Strict-semantic v0.3 MFA contract:
 * `enabled` gates *enrollment of new `EnterpriseConnection` rows* and the
 * `enterprise_sso` first-factor strategy from showing up in fresh sign-ins;
 * existing connections + linked `EnterpriseAccount` rows continue to work
 * (the AU-7 strategy enforces the distinction).
 *
 * Lives on `Environment.user_settings`:
 *   - `authentication_strategies.enterprise_sso.enabled` (bool, default `true`)
 *   - `multi_factor.enterprise_sso_counts_as_mfa` (bool, default `true`)
 */
final readonly class EnterpriseSsoSettings
{
    public function __construct(
        public bool $enabled = true,
        public bool $enterpriseSsoCountsAsMfa = true,
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
        $enterpriseSso = is_array($strategies['enterprise_sso'] ?? null) ? $strategies['enterprise_sso'] : [];

        $multiFactor = is_array($userSettings['multi_factor'] ?? null) ? $userSettings['multi_factor'] : [];

        return new self(
            enabled: (bool) ($enterpriseSso['enabled'] ?? true),
            enterpriseSsoCountsAsMfa: (bool) ($multiFactor['enterprise_sso_counts_as_mfa'] ?? true),
        );
    }

    /**
     * @return array{authentication_strategies: array{enterprise_sso: array{enabled: bool}}, multi_factor: array{enterprise_sso_counts_as_mfa: bool}}
     */
    public function toArray(): array
    {
        return [
            'authentication_strategies' => [
                'enterprise_sso' => ['enabled' => $this->enabled],
            ],
            'multi_factor' => [
                'enterprise_sso_counts_as_mfa' => $this->enterpriseSsoCountsAsMfa,
            ],
        ];
    }
}
