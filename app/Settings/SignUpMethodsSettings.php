<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Per-environment sign-up methods. Lives under
 * `Environment.user_settings.sign_up_methods`. Each method is its own
 * sub-tree; readers should treat unknown keys as defaults so future
 * additions (username, etc.) don't require migrations.
 *
 * This v0.7.2 wave covers the `password.*` block (signup_with_password
 * / add_password) and the `phone.{enabled, required}` block migrated
 * from `attributes.phone_number`. The richer email / phone / username
 * matrix tracked in issue #280 lands later.
 */
final readonly class SignUpMethodsSettings
{
    public function __construct(
        public bool $passwordEnabled = true,
        public bool $signupWithPassword = true,
        public bool $addPassword = true,
        public bool $phoneEnabled = false,
        public bool $phoneRequired = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $userSettings
     */
    public static function fromUserSettings(?array $userSettings): self
    {
        $userSettings = is_array($userSettings) ? $userSettings : [];
        $signUpMethods = is_array($userSettings['sign_up_methods'] ?? null) ? $userSettings['sign_up_methods'] : [];
        $password = is_array($signUpMethods['password'] ?? null) ? $signUpMethods['password'] : [];
        $phone = is_array($signUpMethods['phone'] ?? null) ? $signUpMethods['phone'] : [];

        return new self(
            passwordEnabled: (bool) ($password['enabled'] ?? true),
            signupWithPassword: (bool) ($password['signup_with_password'] ?? true),
            addPassword: (bool) ($password['add_password'] ?? true),
            phoneEnabled: (bool) ($phone['enabled'] ?? false),
            phoneRequired: (bool) ($phone['required'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function withPatch(array $patch): self
    {
        $password = is_array($patch['password'] ?? null) ? $patch['password'] : [];
        $phone = is_array($patch['phone'] ?? null) ? $patch['phone'] : [];

        return new self(
            passwordEnabled: array_key_exists('enabled', $password) ? (bool) $password['enabled'] : $this->passwordEnabled,
            signupWithPassword: array_key_exists('signup_with_password', $password) ? (bool) $password['signup_with_password'] : $this->signupWithPassword,
            addPassword: array_key_exists('add_password', $password) ? (bool) $password['add_password'] : $this->addPassword,
            phoneEnabled: array_key_exists('enabled', $phone) ? (bool) $phone['enabled'] : $this->phoneEnabled,
            phoneRequired: array_key_exists('required', $phone) ? (bool) $phone['required'] : $this->phoneRequired,
        );
    }

    /**
     * @return array{password: array{enabled: bool, signup_with_password: bool, add_password: bool}, phone: array{enabled: bool, required: bool}}
     */
    public function toArray(): array
    {
        return [
            'password' => [
                'enabled' => $this->passwordEnabled,
                'signup_with_password' => $this->signupWithPassword,
                'add_password' => $this->addPassword,
            ],
            'phone' => [
                'enabled' => $this->phoneEnabled,
                'required' => $this->phoneRequired,
            ],
        ];
    }
}
