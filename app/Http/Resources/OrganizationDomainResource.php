<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrganizationDomain;
use App\Models\Verification;

final class OrganizationDomainResource
{
    public static function from(OrganizationDomain $domain, ?Verification $verification = null): array
    {
        $verification ??= $domain->verification_id !== null
            ? Verification::query()->withoutGlobalScopes()->where('id', $domain->verification_id)->first()
            : null;

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
            'verification' => $verification !== null ? [
                'status' => $verification->status,
                'strategy' => $verification->strategy,
                'attempts' => (int) $verification->attempts,
                'expire_at' => $verification->expire_at?->getTimestampMs(),
                'nonce' => $verification->nonce,
            ] : null,
            'created_at' => $domain->created_at?->getTimestampMs(),
            'updated_at' => $domain->updated_at?->getTimestampMs(),
        ];
    }
}
