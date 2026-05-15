<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Per-environment first-factor toggles. Lives under
 * `Environment.user_settings.sign_in_methods`:
 *
 *   - `email.{enabled, code}` — parent + per-strategy toggle for the
 *     email-based first-factor strategies. `reset_password_email_code`
 *     follows the parent `email.enabled` toggle.
 *   - `phone.enabled` — first-factor `phone_code` (#278).
 *   - `username.enabled` — accept username as a sign-in identifier
 *     (#279). Username flows always require a password.
 */
final readonly class SignInMethodsSettings
{
    public function __construct(
        public bool $emailEnabled = true,
        public bool $emailCode = true,
        public bool $phoneEnabled = false,
        public bool $usernameEnabled = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $userSettings
     */
    public static function fromUserSettings(?array $userSettings): self
    {
        $userSettings = is_array($userSettings) ? $userSettings : [];
        $signInMethods = is_array($userSettings['sign_in_methods'] ?? null) ? $userSettings['sign_in_methods'] : [];
        $email = is_array($signInMethods['email'] ?? null) ? $signInMethods['email'] : [];
        $phone = is_array($signInMethods['phone'] ?? null) ? $signInMethods['phone'] : [];
        $username = is_array($signInMethods['username'] ?? null) ? $signInMethods['username'] : [];

        return new self(
            emailEnabled: (bool) ($email['enabled'] ?? true),
            emailCode: (bool) ($email['code'] ?? true),
            phoneEnabled: (bool) ($phone['enabled'] ?? false),
            usernameEnabled: (bool) ($username['enabled'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function withPatch(array $patch): self
    {
        $email = is_array($patch['email'] ?? null) ? $patch['email'] : [];
        $phone = is_array($patch['phone'] ?? null) ? $patch['phone'] : [];
        $username = is_array($patch['username'] ?? null) ? $patch['username'] : [];

        return new self(
            emailEnabled: array_key_exists('enabled', $email) ? (bool) $email['enabled'] : $this->emailEnabled,
            emailCode: array_key_exists('code', $email) ? (bool) $email['code'] : $this->emailCode,
            phoneEnabled: array_key_exists('enabled', $phone) ? (bool) $phone['enabled'] : $this->phoneEnabled,
            usernameEnabled: array_key_exists('enabled', $username) ? (bool) $username['enabled'] : $this->usernameEnabled,
        );
    }

    /**
     * @return array{
     *   email: array{enabled: bool, code: bool},
     *   phone: array{enabled: bool},
     *   username: array{enabled: bool}
     * }
     */
    public function toArray(): array
    {
        return [
            'email' => [
                'enabled' => $this->emailEnabled,
                'code' => $this->emailCode,
            ],
            'phone' => [
                'enabled' => $this->phoneEnabled,
            ],
            'username' => [
                'enabled' => $this->usernameEnabled,
            ],
        ];
    }

    public function emailCodeAllowed(): bool
    {
        return $this->emailEnabled && $this->emailCode;
    }
}
