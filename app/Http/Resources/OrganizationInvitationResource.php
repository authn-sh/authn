<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrganizationInvitation;

final class OrganizationInvitationResource
{
    public static function from(OrganizationInvitation $invitation, ?string $url = null): array
    {
        $role = $invitation->role;

        return [
            'object' => 'organization_invitation',
            'id' => $invitation->id,
            'organization_id' => $invitation->organization_id,
            'email_address' => $invitation->email_address,
            'role' => $role?->key,
            'role_name' => $role?->name,
            'inviter_user_id' => $invitation->inviter_user_id,
            'redirect_url' => $invitation->redirect_url,
            'status' => $invitation->status,
            'public_metadata' => is_array($invitation->public_metadata) ? $invitation->public_metadata : [],
            'url' => $url,
            'expires_at' => $invitation->expires_at?->getTimestampMs(),
            'created_at' => $invitation->created_at?->getTimestampMs(),
            'updated_at' => $invitation->updated_at?->getTimestampMs(),
        ];
    }
}
