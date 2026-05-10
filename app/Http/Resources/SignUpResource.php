<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SignUpAttempt;
use App\Models\Verification;

/**
 * Mirrors openapi `SignUp`. Verification state is no longer inlined as
 * a per-identifier map — it lives on the Challenge sub-resource. The
 * SignUp exposes the live Challenge id (`current_challenge_id`) plus
 * the strategies the client may issue next.
 */
final class SignUpResource
{
    public static function from(?SignUpAttempt $attempt): ?array
    {
        if ($attempt === null) {
            return null;
        }

        return [
            'object' => 'sign_up_attempt',
            'id' => $attempt->id,
            'status' => $attempt->status,
            'required_fields' => self::expandFields($attempt->missing_fields),
            'optional_fields' => [],
            'missing_fields' => self::expandFields($attempt->missing_fields),
            'unverified_fields' => self::expandFields($attempt->unverified_fields),
            'supported_strategies' => self::supportedStrategies($attempt),
            'current_challenge_id' => $attempt->current_challenge_id,
            'email_address' => $attempt->email_address,
            'username' => $attempt->username,
            'phone_number' => $attempt->phone_number,
            'first_name' => $attempt->first_name,
            'last_name' => $attempt->last_name,
            'password_enabled' => $attempt->password_hash !== null,
            'unsafe_metadata' => is_array($attempt->unsafe_metadata) ? $attempt->unsafe_metadata : [],
            'public_metadata' => is_array($attempt->public_metadata) ? $attempt->public_metadata : [],
            'created_session_id' => $attempt->created_session_id,
            'created_user_id' => $attempt->created_user_id,
            'abandon_at' => $attempt->abandon_at->getTimestampMs(),
        ];
    }

    /**
     * @param  mixed  $fields
     * @return list<string>
     */
    private static function expandFields($fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_map('strval', $fields));
    }

    /**
     * @return list<string>
     */
    private static function supportedStrategies(SignUpAttempt $attempt): array
    {
        if ($attempt->status !== SignUpAttempt::STATUS_MISSING_REQUIREMENTS) {
            return [];
        }
        $unverified = is_array($attempt->unverified_fields) ? $attempt->unverified_fields : [];
        if (! in_array('email_address', $unverified, true)) {
            return [];
        }

        return [
            Verification::STRATEGY_EMAIL_CODE,
            Verification::STRATEGY_EMAIL_LINK,
        ];
    }
}
