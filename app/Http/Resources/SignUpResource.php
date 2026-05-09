<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SignUpAttempt;
use App\Models\Verification;

/**
 * Mirrors PLAN §3.3's SignUp shape — what FAPI returns for any sign-up
 * state-machine endpoint. Excludes secrets (password_hash, transfer_token).
 */
final class SignUpResource
{
    public static function from(?SignUpAttempt $attempt): ?array
    {
        if ($attempt === null) {
            return null;
        }

        $verifications = is_array($attempt->verifications) ? $attempt->verifications : [];
        $emailVerificationId = $verifications['email_address'] ?? null;
        $emailVerification = is_string($emailVerificationId)
            ? Verification::query()->withoutGlobalScopes()->where('id', $emailVerificationId)->first()
            : null;

        return [
            'object' => 'sign_up_attempt',
            'id' => $attempt->id,
            'status' => $attempt->status,
            'required_fields' => self::expandFields($attempt->missing_fields),
            'optional_fields' => [],
            'missing_fields' => self::expandFields($attempt->missing_fields),
            'unverified_fields' => self::expandFields($attempt->unverified_fields),
            'verifications' => [
                'email_address' => self::verificationShape($emailVerification),
                'phone_number' => null,
                'web3_wallet' => null,
                'external_account' => null,
            ],
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

    private static function verificationShape(?Verification $verification): ?array
    {
        if ($verification === null) {
            return null;
        }

        return [
            'status' => $verification->status,
            'strategy' => $verification->strategy,
            'attempts' => $verification->attempts,
            'expire_at' => $verification->expire_at->getTimestampMs(),
            'external_verification_redirect_url' => $verification->external_verification_redirect_url,
            'nonce' => $verification->nonce,
            'error' => $verification->error_code !== null ? [
                'code' => $verification->error_code,
                'message' => $verification->error_message,
            ] : null,
        ];
    }
}
