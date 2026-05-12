<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuthorizationGrant;
use App\Models\OauthApplication;

/**
 * FAPI shape for `AuthorizationGrant` rendered on the
 * `/v1/me/authorized-apps` surface. Layers the parent `OauthApplication`
 * row's display fields on top of the grant so the Authorized Apps panel
 * (AU-10 / JS-2) can render the list without a second round-trip.
 */
final class AuthorizationGrantResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(AuthorizationGrant $grant, ?OauthApplication $app = null): array
    {
        $app ??= $grant->oauthApplication;

        return [
            'id' => $grant->id,
            'object' => 'authorization_grant',
            'oauth_application_id' => $grant->oauth_application_id,
            'oauth_application_name' => $app?->name ?? '',
            'scopes' => is_array($grant->scopes) ? array_values($grant->scopes) : [],
            'granted_at' => $grant->granted_at?->getTimestampMs(),
            'revoked_at' => $grant->revoked_at?->getTimestampMs(),
        ];
    }
}
