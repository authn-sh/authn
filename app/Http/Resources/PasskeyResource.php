<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Passkey;

/**
 * Public Passkey shape per OA-1. The credential id, sha-256 hash, COSE
 * public key, and counter are server-only — they never leave the database.
 */
final class PasskeyResource
{
    public static function from(Passkey $passkey): array
    {
        return [
            'object' => 'passkey',
            'id' => $passkey->id,
            'nickname' => $passkey->nickname,
            'transports' => is_array($passkey->transports) ? array_values($passkey->transports) : [],
            'aaguid' => $passkey->aaguid,
            'verified' => $passkey->verified_at !== null,
            'last_used_at' => $passkey->last_used_at?->getTimestampMs(),
            'created_at' => $passkey->created_at?->getTimestampMs(),
            'updated_at' => $passkey->updated_at?->getTimestampMs(),
        ];
    }
}
