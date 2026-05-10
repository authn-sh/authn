<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmailAddress;

final class EmailAddressResource
{
    public static function from(EmailAddress $email): array
    {
        return [
            'object' => 'email_address',
            'id' => $email->id,
            'email_address' => $email->email_address,
            'verified' => $email->verified_at !== null,
            'current_challenge_id' => $email->current_challenge_id,
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
