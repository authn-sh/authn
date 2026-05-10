<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExternalAccount;
use App\Models\OauthProvider;

/**
 * Public ExternalAccount shape per OA-2. Token blobs (access_token,
 * refresh_token, id_token) are encrypted at rest on the model and never
 * appear in any payload — the SDK has no way to read them.
 */
final class ExternalAccountResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(ExternalAccount $row, ?OauthProvider $provider = null): array
    {
        $provider ??= OauthProvider::query()->withoutGlobalScopes()
            ->where('id', $row->oauth_provider_id)
            ->first();

        return [
            'object' => 'external_account',
            'id' => $row->id,
            'provider' => $provider?->name ?? '',
            'provider_key' => $provider?->provider_key ?? '',
            'provider_user_id' => $row->provider_user_id,
            'email_address' => $row->email_address,
            'scopes' => is_array($row->scopes) ? array_values($row->scopes) : [],
            'public_metadata' => is_array($row->public_metadata) ? $row->public_metadata : [],
            'verified' => (bool) $row->verified,
            'linked_at' => $row->linked_at?->getTimestampMs(),
            'last_signed_in_at' => $row->last_signed_in_at?->getTimestampMs(),
            'created_at' => $row->created_at?->getTimestampMs(),
            'updated_at' => $row->updated_at?->getTimestampMs(),
        ];
    }
}
