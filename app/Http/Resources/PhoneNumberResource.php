<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PhoneNumber;

final class PhoneNumberResource
{
    public static function from(PhoneNumber $phone): array
    {
        return [
            'object' => 'phone_number',
            'id' => $phone->id,
            'phone_number' => $phone->phone_number,
            'verified' => $phone->verified_at !== null,
            'current_challenge_id' => $phone->current_challenge_id,
            'is_primary' => (bool) $phone->is_primary,
            'reserved_for_second_factor' => (bool) $phone->reserved_for_second_factor,
            'default_second_factor' => (bool) $phone->default_second_factor,
            'linked_to_external_account_id' => $phone->linked_to_external_account_id,
            'created_at' => $phone->created_at?->getTimestampMs(),
            'updated_at' => $phone->updated_at?->getTimestampMs(),
        ];
    }
}
