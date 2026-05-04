<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Models\Environment;
use App\Models\SigningKey;
use App\Services\Keys\SigningKeyGenerator;
use App\Webhooks\Emitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Per-env signing-key state machine (PLAN §10.4.2):
 *
 *   active.age ≥ rotation_cadence - pre_publish_window AND no pending
 *     → mint a fresh `pending` key (published immediately so JWKS warms up).
 *
 *   pending.published_at ≥ pre_publish_window ago AND active.age ≥ rotation_cadence
 *     → promote pending → active; demote prior active → retiring with
 *       `retire_at = now() + retire_window`.
 *
 *   retiring.retire_at < now()
 *     → flip retiring → expired (still surfaced in JWKS until operator
 *       deletion lands in v0.7).
 *
 * Each transition emits the system.signing_key.rotated_automatic event so
 * dashboards / audit feeds can surface it.
 */
final class RotateSigningKey implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const DEFAULT_ROTATION_CADENCE_DAYS = 90;

    public const DEFAULT_PRE_PUBLISH_WINDOW_SECONDS = 86400;

    public const DEFAULT_RETIRE_WINDOW_SECONDS = 604800;

    public function __construct() {}

    public function handle(SigningKeyGenerator $generator, Emitter $emitter): void
    {
        $envs = Environment::query()->get();
        foreach ($envs as $env) {
            $this->rotateFor($env, $generator, $emitter);
        }
    }

    private function rotateFor(Environment $env, SigningKeyGenerator $generator, Emitter $emitter): void
    {
        $cfg = $this->cadenceFor($env);
        $now = now();

        $active = SigningKey::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('status', SigningKey::STATUS_ACTIVE)
            ->latest('activated_at')
            ->first();
        $pending = SigningKey::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('status', SigningKey::STATUS_PENDING)
            ->latest('published_at')
            ->first();

        // 1. Pre-publish a pending key once we're inside the rollover window.
        if ($active !== null && $pending === null) {
            $rotateAt = $active->activated_at?->copy()->addSeconds($cfg['rotation_cadence_seconds']);
            $publishAt = $rotateAt?->copy()->subSeconds($cfg['pre_publish_window_seconds']);
            if ($publishAt !== null && $publishAt->lessThanOrEqualTo($now)) {
                $pending = $generator->generate($env, SigningKey::STATUS_PENDING);
                $emitter->emit('system.signing_key.rotated_automatic', [
                    'environment_id' => $env->id,
                    'kid' => $pending->id,
                    'phase' => 'pre_published',
                ], $env);
            }
        }

        // 2. Promote pending → active when the window elapsed.
        if ($active !== null && $pending !== null) {
            $rotateAt = $active->activated_at?->copy()->addSeconds($cfg['rotation_cadence_seconds']);
            if ($rotateAt !== null && $rotateAt->lessThanOrEqualTo($now)) {
                $pending->forceFill([
                    'status' => SigningKey::STATUS_ACTIVE,
                    'activated_at' => $now,
                ])->save();
                $active->forceFill([
                    'status' => SigningKey::STATUS_RETIRING,
                    'retire_at' => $now->copy()->addSeconds($cfg['retire_window_seconds']),
                ])->save();
                $emitter->emit('system.signing_key.rotated_automatic', [
                    'environment_id' => $env->id,
                    'promoted_kid' => $pending->id,
                    'retired_kid' => $active->id,
                    'phase' => 'promoted',
                ], $env);
            }
        }

        // 3. Expire any retiring keys whose retire_at has passed.
        $expiring = SigningKey::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('status', SigningKey::STATUS_RETIRING)
            ->where('retire_at', '<', $now)
            ->get();
        foreach ($expiring as $key) {
            $key->forceFill(['status' => SigningKey::STATUS_EXPIRED])->save();
            $emitter->emit('system.signing_key.rotated_automatic', [
                'environment_id' => $env->id,
                'kid' => $key->id,
                'phase' => 'expired',
            ], $env);
        }
    }

    /**
     * @return array{rotation_cadence_seconds:int, pre_publish_window_seconds:int, retire_window_seconds:int}
     */
    private function cadenceFor(Environment $env): array
    {
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $signing = is_array($userSettings['signing_keys'] ?? null) ? $userSettings['signing_keys'] : [];

        return [
            'rotation_cadence_seconds' => (int) (($signing['rotation_cadence_days'] ?? self::DEFAULT_ROTATION_CADENCE_DAYS) * 86400),
            'pre_publish_window_seconds' => (int) ($signing['pre_publish_window_seconds'] ?? self::DEFAULT_PRE_PUBLISH_WINDOW_SECONDS),
            'retire_window_seconds' => (int) ($signing['retire_window_seconds'] ?? self::DEFAULT_RETIRE_WINDOW_SECONDS),
        ];
    }
}
