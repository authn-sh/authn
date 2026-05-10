<?php

declare(strict_types=1);

namespace App\Auth\Mfa;

use App\Models\BackupCode;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Random\Randomizer;

/**
 * Single-use recovery code lifecycle. Plaintext is exposed only via the
 * value object returned by `regenerate()`; rows persist as Argon2id
 * hashes. AU-5's second-factor flow consumes them via `consume()`.
 *
 * Per AU-2's `MultiFactorSettings::strategyEnabled('backup_code')`, the
 * controller layer decides whether the strategy is enabled at the env
 * level — this service stays unaware of that gating.
 */
final class BackupCodesService
{
    public const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public const HALF_LENGTH = 4;

    /**
     * (Re)generate `$count` plaintext codes for `$user`. Wipes any prior
     * un-consumed rows first so the freshly-shown batch is the only
     * spendable one — old shown codes stop working immediately.
     *
     * Returns plaintext codes; the caller wraps them into the one-time
     * `BackupCodeBatch` reveal envelope and discards the array when the
     * response leaves the process.
     *
     * @return list<string>
     */
    public function regenerate(User $user, Environment $environment, int $count): array
    {
        BackupCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->delete();

        $randomizer = new Randomizer;
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateCode($randomizer);
        }

        foreach ($codes as $plain) {
            BackupCode::create([
                'environment_id' => $environment->id,
                'user_id' => $user->id,
                'code_hash' => Hash::make($plain),
            ]);
        }

        return $codes;
    }

    /**
     * Try to consume `$plaintext` against any unspent row on `$user`.
     * Returns `true` and stamps `consumed_at` on the matching row when
     * successful; `false` otherwise.
     */
    public function consume(User $user, string $plaintext): bool
    {
        $plaintext = strtolower(trim($plaintext));
        if (! preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}$/', $plaintext)) {
            return false;
        }

        $rows = BackupCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->get();

        foreach ($rows as $row) {
            if (Hash::check($plaintext, $row->code_hash)) {
                $row->markConsumed();

                return true;
            }
        }

        return false;
    }

    /**
     * Drop every unspent code on the user. Returns the count removed.
     * Consumed rows stay for audit-log purposes.
     */
    public function removeUnspent(User $user): int
    {
        return BackupCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->delete();
    }

    public function unspentCount(User $user): int
    {
        return BackupCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->count();
    }

    private function generateCode(Randomizer $randomizer): string
    {
        $alphabetLen = strlen(self::ALPHABET);
        $halves = [];
        for ($half = 0; $half < 2; $half++) {
            $buf = '';
            for ($i = 0; $i < self::HALF_LENGTH; $i++) {
                $buf .= self::ALPHABET[$randomizer->getInt(0, $alphabetLen - 1)];
            }
            $halves[] = $buf;
        }

        return $halves[0].'-'.$halves[1];
    }
}
