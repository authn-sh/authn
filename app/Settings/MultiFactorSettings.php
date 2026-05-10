<?php

declare(strict_types=1);

namespace App\Settings;

use App\Models\Verification;

/**
 * Per-environment second-factor toggles (PLAN §12.1) read by the FAPI
 * Challenge controller, the Account Portal Security panel, and the
 * BAPI instance settings surface. The dashboard / SDK can mutate these
 * via `PATCH /v1/instance` with a `multi_factor` patch.
 */
final readonly class MultiFactorSettings
{
    public const DEFAULT_BACKUP_CODE_COUNT = 10;

    public const MIN_BACKUP_CODE_COUNT = 4;

    public const MAX_BACKUP_CODE_COUNT = 24;

    public function __construct(
        public bool $totpEnabled = true,
        public bool $backupCodesEnabled = true,
        public int $backupCodesDefaultCount = self::DEFAULT_BACKUP_CODE_COUNT,
        public bool $phoneCodeEnabled = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $userSettings
     */
    public static function fromUserSettings(?array $userSettings): self
    {
        $mf = is_array($userSettings['multi_factor'] ?? null) ? $userSettings['multi_factor'] : [];

        return self::fromArray($mf);
    }

    /**
     * @param  array<string, mixed>  $multiFactor
     */
    public static function fromArray(array $multiFactor): self
    {
        $totp = is_array($multiFactor['totp'] ?? null) ? $multiFactor['totp'] : [];
        $backup = is_array($multiFactor['backup_codes'] ?? null) ? $multiFactor['backup_codes'] : [];
        $phoneCode = is_array($multiFactor['phone_code'] ?? null) ? $multiFactor['phone_code'] : [];

        return new self(
            totpEnabled: (bool) ($totp['enabled'] ?? true),
            backupCodesEnabled: (bool) ($backup['enabled'] ?? true),
            backupCodesDefaultCount: (int) ($backup['default_count'] ?? self::DEFAULT_BACKUP_CODE_COUNT),
            phoneCodeEnabled: (bool) ($phoneCode['enabled'] ?? false),
        );
    }

    /**
     * @return array{totp: array{enabled: bool}, backup_codes: array{enabled: bool, default_count: int}, phone_code: array{enabled: bool}}
     */
    public function toArray(): array
    {
        return [
            'totp' => [
                'enabled' => $this->totpEnabled,
            ],
            'backup_codes' => [
                'enabled' => $this->backupCodesEnabled,
                'default_count' => $this->backupCodesDefaultCount,
            ],
            'phone_code' => [
                'enabled' => $this->phoneCodeEnabled,
            ],
        ];
    }

    /**
     * Apply a partial patch — omitted blocks / fields are left untouched.
     *
     * @param  array<string, mixed>  $patch
     */
    public function withPatch(array $patch): self
    {
        $totpEnabled = $this->totpEnabled;
        $backupCodesEnabled = $this->backupCodesEnabled;
        $backupCodesDefaultCount = $this->backupCodesDefaultCount;
        $phoneCodeEnabled = $this->phoneCodeEnabled;

        if (is_array($patch['totp'] ?? null) && array_key_exists('enabled', $patch['totp'])) {
            $totpEnabled = (bool) $patch['totp']['enabled'];
        }
        if (is_array($patch['backup_codes'] ?? null)) {
            if (array_key_exists('enabled', $patch['backup_codes'])) {
                $backupCodesEnabled = (bool) $patch['backup_codes']['enabled'];
            }
            if (array_key_exists('default_count', $patch['backup_codes'])) {
                $backupCodesDefaultCount = (int) $patch['backup_codes']['default_count'];
            }
        }
        if (is_array($patch['phone_code'] ?? null) && array_key_exists('enabled', $patch['phone_code'])) {
            $phoneCodeEnabled = (bool) $patch['phone_code']['enabled'];
        }

        return new self($totpEnabled, $backupCodesEnabled, $backupCodesDefaultCount, $phoneCodeEnabled);
    }

    /**
     * Whether a strategy name (`totp` / `backup_code`) is enabled at the
     * environment level. Unknown strategy names return false so callers
     * (notably AU-5's `supportedStrategies` narrowing) can pass any spec
     * strategy without first checking the type.
     */
    public function strategyEnabled(string $strategy): bool
    {
        return match ($strategy) {
            Verification::STRATEGY_TOTP => $this->totpEnabled,
            Verification::STRATEGY_BACKUP_CODE => $this->backupCodesEnabled,
            Verification::STRATEGY_PHONE_CODE => $this->phoneCodeEnabled,
            default => false,
        };
    }

    /**
     * Strategy names enabled at the environment level. Used by the public
     * `Environment.auth_config.second_factors` mirror and by AU-5 to
     * narrow `SignInResource::supportedStrategies` for `needs_second_factor`.
     *
     * @return list<string>
     */
    public function enabledStrategies(): array
    {
        $out = [];
        if ($this->totpEnabled) {
            $out[] = Verification::STRATEGY_TOTP;
        }
        if ($this->backupCodesEnabled) {
            $out[] = Verification::STRATEGY_BACKUP_CODE;
        }
        if ($this->phoneCodeEnabled) {
            $out[] = Verification::STRATEGY_PHONE_CODE;
        }

        return $out;
    }
}
