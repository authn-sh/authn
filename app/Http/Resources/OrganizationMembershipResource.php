<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

final class OrganizationMembershipResource
{
    public static function from(OrganizationMembership $membership, bool $includePrivate = false): array
    {
        $role = $membership->role;
        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->where('id', $membership->organization_id)
            ->first();

        return [
            'object' => 'organization_membership',
            'id' => $membership->id,
            'role' => $role?->key,
            'role_name' => $role?->name,
            'permissions' => $role !== null ? $role->permissions->pluck('key')->all() : [],
            'public_metadata' => is_array($membership->public_metadata) ? $membership->public_metadata : [],
            'private_metadata' => $includePrivate && is_array($membership->private_metadata)
                ? $membership->private_metadata
                : [],
            'organization' => $organization !== null ? OrganizationResource::from($organization) : null,
            'public_user_data' => self::publicUserData($membership->user),
            'created_at' => $membership->created_at?->getTimestampMs(),
            'updated_at' => $membership->updated_at?->getTimestampMs(),
        ];
    }

    private static function publicUserData(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }
        $primaryId = $user->primary_email_address_id;
        $primaryEmail = null;
        if (is_string($primaryId) && $primaryId !== '') {
            $row = $user->emailAddresses()->withoutGlobalScopes()->where('id', $primaryId)->first();
            $primaryEmail = $row?->email_address;
        }

        return [
            'user_id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'identifier' => $primaryEmail ?? $user->username,
            'image_url' => $user->image_url,
            'has_image' => (bool) $user->has_image,
        ];
    }
}
