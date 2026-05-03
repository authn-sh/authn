<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmailAddress;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;

/**
 * Mirrors PLAN §3.2's SignIn shape — what FAPI returns for any
 * sign-in state-machine endpoint. Surfaces just enough of the user
 * to render an avatar / name preview without revealing private data
 * before authentication completes.
 */
final class SignInResource
{
    public static function from(?SignInAttempt $attempt): ?array
    {
        if ($attempt === null) {
            return null;
        }

        return [
            'object' => 'sign_in_attempt',
            'id' => $attempt->id,
            'status' => $attempt->status,
            'identifier' => $attempt->identifier,
            'supported_identifiers' => ['email_address'],
            'supported_first_factors' => self::supportedFirstFactors($attempt),
            'supported_second_factors' => [],
            'first_factor_verification' => self::verificationShape(
                $attempt->first_factor_verification_id !== null
                    ? Verification::query()->withoutGlobalScopes()->where('id', $attempt->first_factor_verification_id)->first()
                    : null
            ),
            'second_factor_verification' => null,
            'user_data' => self::userDataPreview($attempt),
            'created_session_id' => $attempt->created_session_id,
            'abandon_at' => $attempt->abandon_at->getTimestampMs(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function supportedFirstFactors(SignInAttempt $attempt): array
    {
        if ($attempt->identifier === null) {
            return [];
        }

        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('email_address', strtolower($attempt->identifier))
            ->first();
        if ($email === null) {
            return [];
        }

        $user = User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first();
        if ($user === null) {
            return [];
        }

        $factors = [];
        if ($user->password_hash !== null) {
            $factors[] = ['strategy' => Verification::STRATEGY_PASSWORD];
        }
        $factors[] = [
            'strategy' => Verification::STRATEGY_EMAIL_CODE,
            'email_address_id' => $email->id,
            'safe_identifier' => self::redactEmail($email->email_address),
        ];
        $factors[] = [
            'strategy' => Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE,
            'email_address_id' => $email->id,
            'safe_identifier' => self::redactEmail($email->email_address),
        ];

        return $factors;
    }

    private static function verificationShape(?Verification $verification): ?array
    {
        if ($verification === null) {
            return null;
        }

        return [
            'object' => 'verification',
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

    private static function userDataPreview(SignInAttempt $attempt): ?array
    {
        if ($attempt->identifier === null) {
            return null;
        }

        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('email_address', strtolower($attempt->identifier))
            ->first();
        if ($email === null) {
            return null;
        }
        $user = User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first();
        if ($user === null) {
            return null;
        }

        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'image_url' => $user->image_url,
            'has_image' => (bool) $user->has_image,
        ];
    }

    private static function redactEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $first = $local !== '' ? $local[0] : '';

        return $first.str_repeat('*', max(1, strlen($local) - 1)).'@'.$domain;
    }
}
