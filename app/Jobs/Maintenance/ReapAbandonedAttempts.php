<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Models\Client;
use App\Models\SignInAttempt;
use App\Models\SignUpAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Sweeps SignInAttempt and SignUpAttempt rows whose abandon_at has passed
 * but that never reached a terminal state. Flips them to `abandoned` and
 * detaches the row from the owning Client's `current_*_attempt_id` so the
 * SDK boots into a clean state on the next /v1/client read.
 *
 * Scheduled every minute (see routes/console.php). Cap of 1000 rows per
 * tick — uses LIMIT … FOR UPDATE SKIP LOCKED on Postgres so multiple
 * Horizon workers cooperate cleanly. SQLite (test) skips the locking
 * clauses transparently.
 */
final class ReapAbandonedAttempts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const BATCH = 1000;

    public function __construct() {}

    public function handle(): void
    {
        $this->reap(SignInAttempt::class, 'current_sign_in_attempt_id');
        $this->reap(SignUpAttempt::class, 'current_sign_up_attempt_id');
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function reap(string $model, string $clientColumn): void
    {
        $now = now();
        $terminal = $model === SignInAttempt::class
            ? [SignInAttempt::STATUS_COMPLETE, SignInAttempt::STATUS_ABANDONED]
            : [SignUpAttempt::STATUS_COMPLETE, SignUpAttempt::STATUS_ABANDONED];

        DB::transaction(function () use ($model, $clientColumn, $terminal, $now): void {
            $query = $model::query()
                ->withoutGlobalScopes()
                ->where('abandon_at', '<', $now)
                ->whereNotIn('status', $terminal)
                ->limit(self::BATCH);
            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lockForUpdate()->withoutGlobalScopes();
            }
            $rows = $query->get();
            if ($rows->isEmpty()) {
                return;
            }

            $ids = $rows->pluck('id')->all();
            // Bypass the model `updating` transition guard — these rows are
            // explicitly being moved to a terminal state.
            $model::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->update([
                    'status' => $model === SignInAttempt::class
                        ? SignInAttempt::STATUS_ABANDONED
                        : SignUpAttempt::STATUS_ABANDONED,
                    'updated_at' => $now,
                ]);

            Client::query()
                ->withoutGlobalScopes()
                ->whereIn($clientColumn, $ids)
                ->update([$clientColumn => null]);
        });
    }
}
