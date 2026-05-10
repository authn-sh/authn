<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Models\BackupCode;
use App\Models\Environment;
use App\Models\TotpSecret;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Two MFA-table reapers running off the same daily tick:
 *
 *   - `TotpSecret` rows where `verified_at IS NULL` and `created_at`
 *     is older than 24h — abandoned enrolment attempts (the user
 *     closed the modal without typing the first code).
 *   - `BackupCode` rows with a stamped `consumed_at` older than the
 *     env's `audit_log_retention_days` setting (default 90). Once a
 *     code has been consumed, the audit/observability story moves to
 *     the `auth.mfa.*` log entries; the hash itself is no longer
 *     needed and just bloats the table.
 *
 * Per-env retention so an operator who sets `audit_log_retention_days`
 * to 30 doesn't keep consumed code rows for 90.
 */
final class PruneMfaArtifacts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const ABANDONED_TOTP_HOURS = 24;

    public const DEFAULT_BACKUP_CODE_RETENTION_DAYS = 90;

    public function __construct() {}

    public function handle(): void
    {
        $this->pruneAbandonedTotpSecrets();
        $this->pruneStaleConsumedBackupCodes();
    }

    private function pruneAbandonedTotpSecrets(): void
    {
        $threshold = now()->subHours(self::ABANDONED_TOTP_HOURS);

        TotpSecret::query()
            ->withoutGlobalScopes()
            ->whereNull('verified_at')
            ->where('created_at', '<', $threshold)
            ->delete();
    }

    private function pruneStaleConsumedBackupCodes(): void
    {
        $envs = Environment::query()->withoutGlobalScopes()->get(['id', 'user_settings']);
        foreach ($envs as $env) {
            $retentionDays = $this->retentionDaysFor($env);
            $threshold = now()->subDays($retentionDays);

            BackupCode::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->whereNotNull('consumed_at')
                ->where('consumed_at', '<', $threshold)
                ->delete();
        }
    }

    private function retentionDaysFor(Environment $env): int
    {
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $value = $userSettings['audit_log_retention_days'] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return self::DEFAULT_BACKUP_CODE_RETENTION_DAYS;
    }
}
