<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Per-environment sign-up methods. Lives under
 * `Environment.user_settings.sign_up_methods`. Each method is its own
 * sub-tree; readers should treat unknown keys as defaults so future
 * additions don't require migrations.
 *
 * Covers four sub-trees:
 *
 *   - `email.{enabled, required, verify, verify_code, restrict_changes}`
 *   - `phone.{enabled, required, verify, restrict_changes}`
 *   - `username.{enabled, required, restrict_changes}`
 *   - `password.{enabled, signup_with_password, add_password}`
 *
 * `verify_link` was dropped with the email_link removal (#283).
 */
final readonly class SignUpMethodsSettings
{
    public function __construct(
        public bool $emailEnabled = true,
        public bool $emailRequired = true,
        public bool $emailVerify = true,
        public bool $emailVerifyCode = true,
        public bool $emailRestrictChanges = false,
        public bool $phoneEnabled = false,
        public bool $phoneRequired = false,
        public bool $phoneVerify = true,
        public bool $phoneRestrictChanges = false,
        public bool $usernameEnabled = false,
        public bool $usernameRequired = false,
        public bool $usernameRestrictChanges = false,
        public bool $passwordEnabled = true,
        public bool $signupWithPassword = true,
        public bool $addPassword = true,
    ) {}

    /**
     * @param  array<string, mixed>|null  $userSettings
     */
    public static function fromUserSettings(?array $userSettings): self
    {
        $userSettings = is_array($userSettings) ? $userSettings : [];
        $signUpMethods = is_array($userSettings['sign_up_methods'] ?? null) ? $userSettings['sign_up_methods'] : [];
        $email = is_array($signUpMethods['email'] ?? null) ? $signUpMethods['email'] : [];
        $phone = is_array($signUpMethods['phone'] ?? null) ? $signUpMethods['phone'] : [];
        $username = is_array($signUpMethods['username'] ?? null) ? $signUpMethods['username'] : [];
        $password = is_array($signUpMethods['password'] ?? null) ? $signUpMethods['password'] : [];

        return new self(
            emailEnabled: (bool) ($email['enabled'] ?? true),
            emailRequired: (bool) ($email['required'] ?? true),
            emailVerify: (bool) ($email['verify'] ?? true),
            emailVerifyCode: (bool) ($email['verify_code'] ?? true),
            emailRestrictChanges: (bool) ($email['restrict_changes'] ?? false),
            phoneEnabled: (bool) ($phone['enabled'] ?? false),
            phoneRequired: (bool) ($phone['required'] ?? false),
            phoneVerify: (bool) ($phone['verify'] ?? true),
            phoneRestrictChanges: (bool) ($phone['restrict_changes'] ?? false),
            usernameEnabled: (bool) ($username['enabled'] ?? false),
            usernameRequired: (bool) ($username['required'] ?? false),
            usernameRestrictChanges: (bool) ($username['restrict_changes'] ?? false),
            passwordEnabled: (bool) ($password['enabled'] ?? true),
            signupWithPassword: (bool) ($password['signup_with_password'] ?? true),
            addPassword: (bool) ($password['add_password'] ?? true),
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
        $password = is_array($patch['password'] ?? null) ? $patch['password'] : [];

        return new self(
            emailEnabled: array_key_exists('enabled', $email) ? (bool) $email['enabled'] : $this->emailEnabled,
            emailRequired: array_key_exists('required', $email) ? (bool) $email['required'] : $this->emailRequired,
            emailVerify: array_key_exists('verify', $email) ? (bool) $email['verify'] : $this->emailVerify,
            emailVerifyCode: array_key_exists('verify_code', $email) ? (bool) $email['verify_code'] : $this->emailVerifyCode,
            emailRestrictChanges: array_key_exists('restrict_changes', $email) ? (bool) $email['restrict_changes'] : $this->emailRestrictChanges,
            phoneEnabled: array_key_exists('enabled', $phone) ? (bool) $phone['enabled'] : $this->phoneEnabled,
            phoneRequired: array_key_exists('required', $phone) ? (bool) $phone['required'] : $this->phoneRequired,
            phoneVerify: array_key_exists('verify', $phone) ? (bool) $phone['verify'] : $this->phoneVerify,
            phoneRestrictChanges: array_key_exists('restrict_changes', $phone) ? (bool) $phone['restrict_changes'] : $this->phoneRestrictChanges,
            usernameEnabled: array_key_exists('enabled', $username) ? (bool) $username['enabled'] : $this->usernameEnabled,
            usernameRequired: array_key_exists('required', $username) ? (bool) $username['required'] : $this->usernameRequired,
            usernameRestrictChanges: array_key_exists('restrict_changes', $username) ? (bool) $username['restrict_changes'] : $this->usernameRestrictChanges,
            passwordEnabled: array_key_exists('enabled', $password) ? (bool) $password['enabled'] : $this->passwordEnabled,
            signupWithPassword: array_key_exists('signup_with_password', $password) ? (bool) $password['signup_with_password'] : $this->signupWithPassword,
            addPassword: array_key_exists('add_password', $password) ? (bool) $password['add_password'] : $this->addPassword,
        );
    }

    /**
     * @return array{
     *   email: array{enabled: bool, required: bool, verify: bool, verify_code: bool, restrict_changes: bool},
     *   phone: array{enabled: bool, required: bool, verify: bool, restrict_changes: bool},
     *   username: array{enabled: bool, required: bool, restrict_changes: bool},
     *   password: array{enabled: bool, signup_with_password: bool, add_password: bool}
     * }
     */
    public function toArray(): array
    {
        return [
            'email' => [
                'enabled' => $this->emailEnabled,
                'required' => $this->emailRequired,
                'verify' => $this->emailVerify,
                'verify_code' => $this->emailVerifyCode,
                'restrict_changes' => $this->emailRestrictChanges,
            ],
            'phone' => [
                'enabled' => $this->phoneEnabled,
                'required' => $this->phoneRequired,
                'verify' => $this->phoneVerify,
                'restrict_changes' => $this->phoneRestrictChanges,
            ],
            'username' => [
                'enabled' => $this->usernameEnabled,
                'required' => $this->usernameRequired,
                'restrict_changes' => $this->usernameRestrictChanges,
            ],
            'password' => [
                'enabled' => $this->passwordEnabled,
                'signup_with_password' => $this->signupWithPassword,
                'add_password' => $this->addPassword,
            ],
        ];
    }
}
