<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrganizationDomain;

final class OrganizationDomainResource
{
    public static function from(OrganizationDomain $domain): array
    {
        return [
            'object' => 'organization_domain',
            'id' => $domain->id,
            'organization_id' => $domain->organization_id,
            'name' => $domain->name,
            'verified' => (bool) $domain->verified,
            'enrollment_mode' => $domain->enrollment_mode,
            'affiliation_email_address' => $domain->affiliation_email_address,
            'total_pending_invitations' => (int) $domain->total_pending_invitations,
            'total_pending_suggestions' => (int) $domain->total_pending_suggestions,
            'current_challenge_id' => $domain->current_challenge_id,
            'created_at' => $domain->created_at?->getTimestampMs(),
            'updated_at' => $domain->updated_at?->getTimestampMs(),
        ];
    }
}
