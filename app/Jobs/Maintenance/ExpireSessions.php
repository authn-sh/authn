<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Models\Environment;
use App\Models\Session;
use App\Webhooks\Emitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Sweeps live Session rows whose expire_at has passed and flips them to
 * `expired`. Emits one `session.ended` webhook per session with
 * `data.reason = "expired"` so consumers can distinguish from
 * user-initiated sign-outs.
 *
 * Cap of 1000 rows per tick. Same SKIP LOCKED pattern as
 * ReapAbandonedAttempts.
 */
final class ExpireSessions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const BATCH = 1000;

    public function __construct() {}

    public function handle(Emitter $emitter): void
    {
        $now = now();

        $rows = collect();
        DB::transaction(function () use ($now, &$rows): void {
            $query = Session::query()
                ->withoutGlobalScopes()
                ->whereIn('status', Session::LIVE_STATUSES)
                ->where('expire_at', '<', $now)
                ->limit(self::BATCH);
            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }
            $rows = $query->get();
            if ($rows->isEmpty()) {
                return;
            }

            Session::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $rows->pluck('id'))
                ->update([
                    'status' => Session::STATUS_EXPIRED,
                    'updated_at' => $now,
                ]);
        });

        if ($rows->isEmpty()) {
            return;
        }
        // Emit outside the transaction so we don't block the row release on
        // the webhook DB writes.
        foreach ($rows as $session) {
            $env = Environment::query()->withoutGlobalScopes()->where('id', $session->environment_id)->first();
            if ($env === null) {
                continue;
            }
            $emitter->emit('session.ended', [
                'object' => 'session',
                'id' => $session->id,
                'status' => Session::STATUS_EXPIRED,
                'client_id' => $session->client_id,
                'user_id' => $session->user_id,
                'reason' => 'expired',
            ], $env, (bool) $session->was_test);
        }
    }
}
