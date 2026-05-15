<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Per-environment SDK fallback destinations. The SDK uses these when the
 * tenant app doesn't supply an override at runtime (afterSignInUrl,
 * afterSignUpUrl, …). All five fields are nullable; an empty string
 * persists as `null` ("no fallback set").
 *
 * Lives under `Environment.user_settings.redirects`.
 */
final readonly class RedirectsSettings
{
    /** Maximum URL length the dashboard will accept. */
    public const MAX_URL_LENGTH = 2048;

    public function __construct(
        public ?string $afterSignUp = null,
        public ?string $afterSignIn = null,
        public ?string $home = null,
        public ?string $afterCreateOrganization = null,
        public ?string $afterLeaveOrganization = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $userSettings
     */
    public static function fromUserSettings(?array $userSettings): self
    {
        $userSettings = is_array($userSettings) ? $userSettings : [];
        $redirects = is_array($userSettings['redirects'] ?? null) ? $userSettings['redirects'] : [];

        return new self(
            afterSignUp: self::str($redirects, 'after_sign_up'),
            afterSignIn: self::str($redirects, 'after_sign_in'),
            home: self::str($redirects, 'home'),
            afterCreateOrganization: self::str($redirects, 'after_create_organization'),
            afterLeaveOrganization: self::str($redirects, 'after_leave_organization'),
        );
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function withPatch(array $patch): self
    {
        return new self(
            afterSignUp: array_key_exists('after_sign_up', $patch) ? self::nullable($patch['after_sign_up']) : $this->afterSignUp,
            afterSignIn: array_key_exists('after_sign_in', $patch) ? self::nullable($patch['after_sign_in']) : $this->afterSignIn,
            home: array_key_exists('home', $patch) ? self::nullable($patch['home']) : $this->home,
            afterCreateOrganization: array_key_exists('after_create_organization', $patch) ? self::nullable($patch['after_create_organization']) : $this->afterCreateOrganization,
            afterLeaveOrganization: array_key_exists('after_leave_organization', $patch) ? self::nullable($patch['after_leave_organization']) : $this->afterLeaveOrganization,
        );
    }

    /**
     * @return array{after_sign_up: ?string, after_sign_in: ?string, home: ?string, after_create_organization: ?string, after_leave_organization: ?string}
     */
    public function toArray(): array
    {
        return [
            'after_sign_up' => $this->afterSignUp,
            'after_sign_in' => $this->afterSignIn,
            'home' => $this->home,
            'after_create_organization' => $this->afterCreateOrganization,
            'after_leave_organization' => $this->afterLeaveOrganization,
        ];
    }

    /**
     * @param  array<string, mixed>  $redirects
     */
    private static function str(array $redirects, string $key): ?string
    {
        $value = $redirects[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private static function nullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
