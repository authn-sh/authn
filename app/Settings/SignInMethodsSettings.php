<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Per-environment first-factor toggles. Lives under
 * `Environment.user_settings.sign_in_methods`:
 *
 *   - `email.enabled` (bool, default `true`) — the parent toggle. When
 *     off, email-based first-factor strategies disappear from
 *     `auth_config.first_factors` entirely.
 *   - `email.code` (bool, default `true`) — narrows `email_code`
 *     specifically. The reset_password_email_code strategy follows the
 *     parent `email.enabled` toggle.
 *
 * Phone / username toggles will join here once their backing strategies
 * (#278 / #279) land.
 */
final readonly class SignInMethodsSettings
{
    public function __construct(
        public bool $emailEnabled = true,
        public bool $emailCode = true,
    ) {}

    /**
     * @param  array<string, mixed>|null  $userSettings
     */
    public static function fromUserSettings(?array $userSettings): self
    {
        $userSettings = is_array($userSettings) ? $userSettings : [];
        $signInMethods = is_array($userSettings['sign_in_methods'] ?? null) ? $userSettings['sign_in_methods'] : [];
        $email = is_array($signInMethods['email'] ?? null) ? $signInMethods['email'] : [];

        return new self(
            emailEnabled: (bool) ($email['enabled'] ?? true),
            emailCode: (bool) ($email['code'] ?? true),
        );
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function withPatch(array $patch): self
    {
        $email = is_array($patch['email'] ?? null) ? $patch['email'] : [];

        return new self(
            emailEnabled: array_key_exists('enabled', $email) ? (bool) $email['enabled'] : $this->emailEnabled,
            emailCode: array_key_exists('code', $email) ? (bool) $email['code'] : $this->emailCode,
        );
    }

    /**
     * @return array{email: array{enabled: bool, code: bool}}
     */
    public function toArray(): array
    {
        return [
            'email' => [
                'enabled' => $this->emailEnabled,
                'code' => $this->emailCode,
            ],
        ];
    }

    public function emailCodeAllowed(): bool
    {
        return $this->emailEnabled && $this->emailCode;
    }
}
