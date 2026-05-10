<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;

/**
 * One-time reveal envelope per openapi components/schemas/BackupCodeBatch.yaml.
 * Plaintext codes are passed in only on the regeneration response;
 * subsequent reads (status, delete) emit `count: 0, codes: []` for
 * shape consistency.
 */
final class BackupCodeBatchResource
{
    /**
     * @param  list<string>  $codes
     * @return array<string, mixed>
     */
    public static function from(User $user, array $codes, ?int $generatedAtMs = null): array
    {
        return [
            'object' => 'backup_code_batch',
            'user_id' => $user->id,
            'count' => count($codes),
            'codes' => $codes,
            'generated_at' => $generatedAtMs ?? now()->getTimestampMs(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function empty(User $user): array
    {
        return self::from($user, []);
    }
}
