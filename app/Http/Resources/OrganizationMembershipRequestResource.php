<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrganizationMembershipRequest;

final class OrganizationMembershipRequestResource
{
    public static function from(OrganizationMembershipRequest $request): array
    {
        return [
            'object' => 'organization_membership_request',
            'id' => $request->id,
            'organization_id' => $request->organization_id,
            'user_id' => $request->user_id,
            'status' => $request->status,
            'created_at' => $request->created_at?->getTimestampMs(),
            'updated_at' => $request->updated_at?->getTimestampMs(),
        ];
    }
}
