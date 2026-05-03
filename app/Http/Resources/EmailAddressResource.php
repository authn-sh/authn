<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmailAddress;
use App\Models\Verification;

final class EmailAddressResource
{
    public static function from(EmailAddress $email): array
    {
        $verification = Verification::query()
            ->withoutGlobalScopes()
            ->where('verifiable_type', $email->getMorphClass())
            ->where('verifiable_id', $email->id)
            ->latest('id')
            ->first();

        return [
            'object' => 'email_address',
            'id' => $email->id,
            'email_address' => $email->email_address,
            'verification' => $verification === null ? null : [
                'object' => 'verification',
                'status' => $verification->status,
                'strategy' => $verification->strategy,
                'attempts' => $verification->attempts,
                'expire_at' => $verification->expire_at->getTimestampMs(),
                'error' => $verification->error_code !== null ? [
                    'code' => $verification->error_code,
                    'message' => $verification->error_message,
                ] : null,
            ],
            // Per OA-2: the spec exposes a uniform `linked_to` array for
            // OAuth / enterprise / passkey-bound emails (empty in v0.1).
            'linked_to' => $email->linked_to_external_account_id !== null
                ? [['id' => $email->linked_to_external_account_id, 'type' => 'external_account']]
                : [],
            'reserved' => $email->user_id === null,
            'created_at' => $email->created_at?->getTimestampMs(),
            'updated_at' => $email->updated_at?->getTimestampMs(),
        ];
    }
}
