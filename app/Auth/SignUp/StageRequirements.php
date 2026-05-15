<?php

declare(strict_types=1);

namespace App\Auth\SignUp;

use App\Models\Environment;
use App\Settings\SignUpMethodsSettings;

/**
 * Validates a sign-up payload against the env's `user_settings.attributes`
 * matrix. Returns:
 *
 *   - rejected[]        — fields the body provided that the env has disabled
 *                         (controller maps to 422 form_param_unknown)
 *   - missing_fields[]  — required fields not yet supplied
 *   - unverified_fields[] — required-and-verifiable fields with no proof yet
 *
 * Default attribute matrix matches what EnvironmentResource publishes when no
 * per-env override exists (email + password required; first/last_name optional;
 * everything else disabled). Env overrides are merged on top.
 */
final class StageRequirements
{
    public const KNOWN_ATTRIBUTES = [
        'email_address',
        'username',
        'first_name',
        'last_name',
        'password',
    ];

    private const DEFAULTS = [
        'email_address' => [
            'enabled' => true,
            'required' => true,
            'verifications' => ['email_code'],
            'verify_at_sign_up' => true,
        ],
        'password' => [
            'enabled' => true,
            'required' => true,
            'verifications' => [],
            'verify_at_sign_up' => false,
        ],
        'username' => [
            'enabled' => false,
            'required' => false,
            'verifications' => [],
            'verify_at_sign_up' => false,
        ],
        'first_name' => [
            'enabled' => true,
            'required' => false,
            'verifications' => [],
            'verify_at_sign_up' => false,
        ],
        'last_name' => [
            'enabled' => true,
            'required' => false,
            'verifications' => [],
            'verify_at_sign_up' => false,
        ],
    ];

    /**
     * @param  array<string, mixed>  $supplied  attribute => value (already-staged fields included)
     * @param  array<string, mixed>  $alreadyVerified  attribute => bool (e.g. ['email_address' => true] when a verification ran)
     * @return array{rejected: list<string>, missing_fields: list<string>, unverified_fields: list<string>, matrix: array<string, array<string, mixed>>}
     */
    public function evaluate(Environment $environment, array $supplied, array $alreadyVerified = []): array
    {
        $matrix = $this->matrix($environment);

        $rejected = [];
        $missing = [];
        $unverified = [];

        foreach (self::KNOWN_ATTRIBUTES as $attr) {
            $cfg = $matrix[$attr] ?? self::DEFAULTS[$attr];
            $value = $supplied[$attr] ?? null;
            $hasValue = is_string($value) && $value !== '';

            if (! ($cfg['enabled'] ?? false)) {
                if ($hasValue) {
                    $rejected[] = $attr;
                }

                continue;
            }

            if (($cfg['required'] ?? false) && ! $hasValue) {
                $missing[] = $attr;

                continue;
            }

            if (($cfg['verify_at_sign_up'] ?? false) && $hasValue && empty($alreadyVerified[$attr])) {
                $unverified[] = $attr;
            }
        }

        return [
            'rejected' => $rejected,
            'missing_fields' => $missing,
            'unverified_fields' => $unverified,
            'matrix' => $matrix,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function matrix(Environment $environment): array
    {
        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];
        $overrides = is_array($userSettings['attributes'] ?? null) ? $userSettings['attributes'] : [];
        $matrix = self::DEFAULTS;
        foreach ($overrides as $attr => $cfg) {
            if (! is_array($cfg)) {
                continue;
            }
            $matrix[$attr] = array_merge($matrix[$attr] ?? [], $cfg);
        }

        // Honour the new sign_up_methods.password.{enabled,signup_with_password}
        // toggles (#281). When either is off, password is skipped at sign-up
        // entirely — the user lands without a password_hash and can add one
        // later via /me/password (subject to add_password).
        $signUpMethods = SignUpMethodsSettings::fromUserSettings($userSettings);
        if (! $signUpMethods->passwordEnabled || ! $signUpMethods->signupWithPassword) {
            $matrix['password']['enabled'] = false;
            $matrix['password']['required'] = false;
        }

        return $matrix;
    }
}
