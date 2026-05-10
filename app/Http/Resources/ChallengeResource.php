<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Challenge;

/**
 * Mirrors openapi `Challenge.yaml`. Uniform across all parent types —
 * exactly one of {sign_in_id, sign_up_id, email_address_id,
 * organization_domain_id} is non-null per row.
 */
final class ChallengeResource
{
    public static function from(?Challenge $challenge): ?array
    {
        if ($challenge === null) {
            return null;
        }

        return [
            'object' => 'challenge',
            'id' => $challenge->id,
            'sign_in_id' => $challenge->parent_type === Challenge::PARENT_SIGN_IN ? $challenge->parent_id : null,
            'sign_up_id' => $challenge->parent_type === Challenge::PARENT_SIGN_UP ? $challenge->parent_id : null,
            'email_address_id' => $challenge->parent_type === Challenge::PARENT_EMAIL_ADDRESS ? $challenge->parent_id : null,
            'organization_domain_id' => $challenge->parent_type === Challenge::PARENT_ORGANIZATION_DOMAIN ? $challenge->parent_id : null,
            'step' => $challenge->step,
            'strategy' => $challenge->strategy,
            'status' => $challenge->status,
            'attempts' => (int) $challenge->attempts,
            'expire_at' => $challenge->expire_at->getTimestampMs(),
            'nonce' => $challenge->nonce,
            'external_verification_redirect_url' => $challenge->external_verification_redirect_url,
            'error' => $challenge->error_code !== null ? [
                'code' => $challenge->error_code,
                'message' => (string) $challenge->error_message,
            ] : null,
            'created_at' => $challenge->created_at?->getTimestampMs(),
            'updated_at' => $challenge->updated_at?->getTimestampMs(),
        ];
    }
}
