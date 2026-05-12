<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnterpriseAccount;

/**
 * BAPI shape for `EnterpriseAccount`. Mirrors OA-2. The encrypted
 * `id_token` is never returned; `public_metadata` carries the raw IdP
 * attributes the connection's `attribute_mapping` didn't map onto a
 * User field.
 */
final class EnterpriseAccountResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(EnterpriseAccount $row): array
    {
        return [
            'object' => 'enterprise_account',
            'id' => $row->id,
            'enterprise_connection_id' => $row->enterprise_connection_id,
            'provider_user_id' => $row->provider_user_id,
            'email_address' => $row->email_address,
            'verified' => (bool) $row->verified,
            'public_metadata' => is_array($row->public_metadata) ? $row->public_metadata : [],
            'linked_at' => $row->linked_at->getTimestampMs(),
            'last_signed_in_at' => $row->last_signed_in_at?->getTimestampMs(),
            'created_at' => $row->created_at?->getTimestampMs(),
            'updated_at' => $row->updated_at?->getTimestampMs(),
        ];
    }
}
