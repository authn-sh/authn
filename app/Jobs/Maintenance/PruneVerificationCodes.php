<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Models\VerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Wipes secret material that's no longer redeemable:
 *
 *   - rows whose `consumed_at` is set (one-shot codes are done after use), and
 *   - rows whose `expires_at` is more than 24 hours past.
 *
 * Verifications themselves are kept (audit value); only the secret bytes
 * inside `verification_codes` are removed. Cap of 5000 rows per tick.
 */
final class PruneVerificationCodes implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const BATCH = 5000;

    public const STALE_AFTER_HOURS = 24;

    public function __construct() {}

    public function handle(): void
    {
        $now = now();
        $threshold = $now->copy()->subHours(self::STALE_AFTER_HOURS);

        DB::transaction(function () use ($threshold): void {
            $query = VerificationCode::query()
                ->where(function ($q) use ($threshold): void {
                    $q->whereNotNull('consumed_at')
                        ->orWhere('expires_at', '<', $threshold);
                })
                ->limit(self::BATCH);
            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }
            $ids = $query->pluck('id');
            if ($ids->isEmpty()) {
                return;
            }
            VerificationCode::query()->whereIn('id', $ids)->delete();
        });
    }
}
