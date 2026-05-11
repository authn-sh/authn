<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;

/**
 * The public-facing User shape served by `/v1/me` and `/v1/users/{id}`.
 *
 * `private_metadata` is BAPI-only: dashboard / server-to-server may set and
 * read it; it never crosses the FAPI boundary. `password_hash` is hidden via
 * the model's `$hidden` and never re-added here.
 */
final class UserResource
{
    public static function from(User $user, bool $includePrivate = false): array
    {
        $emails = $user->emailAddresses()->withoutGlobalScopes()->get();
        $phones = $user->phoneNumbers()->withoutGlobalScopes()->get();

        $shape = [
            'object' => 'user',
            'id' => $user->id,
            'external_id' => $user->external_id,
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'image_url' => $user->image_url,
            'has_image' => (bool) $user->has_image,
            'primary_email_address_id' => $user->primary_email_address_id,
            'email_addresses' => $emails->map(fn ($email) => EmailAddressResource::from($email))->all(),
            'primary_phone_number_id' => $user->primary_phone_number_id,
            'phone_numbers' => $phones->map(fn ($phone) => PhoneNumberResource::from($phone))->all(),
            // v0.5+: stable arrays so SDK types are forward-compatible.
            'external_accounts' => [],
            'enterprise_accounts' => [],
            'passkeys' => [],
            'passkey_count' => $user->passkey_count,
            'password_enabled' => $user->password_hash !== null,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'totp_enabled' => (bool) $user->totp_enabled,
            'backup_code_enabled' => (bool) $user->backup_code_enabled,
            'banned' => (bool) $user->banned,
            'locked' => (bool) $user->locked,
            'lockout_expires_at' => $user->lockout_expires_at?->getTimestampMs(),
            'last_sign_in_at' => $user->last_sign_in_at?->getTimestampMs(),
            'last_active_at' => $user->last_active_at?->getTimestampMs(),
            'delete_self_enabled' => (bool) $user->delete_self_enabled,
            'public_metadata' => is_array($user->public_metadata) ? $user->public_metadata : [],
            'unsafe_metadata' => is_array($user->unsafe_metadata) ? $user->unsafe_metadata : [],
            'locale' => $user->locale,
            'created_at' => $user->created_at?->getTimestampMs(),
            'updated_at' => $user->updated_at?->getTimestampMs(),
        ];
        $shape['private_metadata'] = $includePrivate && is_array($user->private_metadata)
            ? $user->private_metadata
            : [];

        return $shape;
    }
}
