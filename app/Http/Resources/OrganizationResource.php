<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;

final class OrganizationResource
{
    public static function from(Organization $org, bool $includePrivate = false): array
    {
        return [
            'object' => 'organization',
            'id' => $org->id,
            'name' => $org->name,
            'slug' => $org->slug,
            'image_url' => $org->image_path,
            'has_image' => $org->image_path !== null,
            'members_count' => (int) $org->members_count,
            'pending_invitations_count' => (int) $org->pending_invitations_count,
            'max_allowed_memberships' => $org->max_allowed_memberships,
            'admin_delete_enabled' => (bool) $org->admin_delete_enabled,
            'public_metadata' => is_array($org->public_metadata) ? $org->public_metadata : [],
            'private_metadata' => $includePrivate && is_array($org->private_metadata)
                ? $org->private_metadata
                : [],
            'created_by' => $org->created_by_user_id,
            'created_at' => $org->created_at?->getTimestampMs(),
            'updated_at' => $org->updated_at?->getTimestampMs(),
        ];
    }
}
