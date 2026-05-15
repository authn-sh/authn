<?php

declare(strict_types=1);

namespace App\Services\SignIn;

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\User;

/**
 * Resolves a free-form SignIn identifier to a User. Sniffs the identifier
 * shape (email vs E.164 phone vs username) and queries the appropriate
 * table.
 *
 * Issued in v0.7.3 (#278 / #279) so SignInResource + the challenge flow
 * can support phone and username as first-factor identifiers without the
 * SDK having to send `identifier_type` explicitly.
 */
final class IdentifierResolver
{
    public const TYPE_EMAIL = 'email_address';

    public const TYPE_PHONE = 'phone_number';

    public const TYPE_USERNAME = 'username';

    /** E.164: `+` plus 7–15 digits. */
    private const E164_PATTERN = '/^\+[1-9]\d{6,14}$/';

    public static function detect(string $identifier): string
    {
        if (str_contains($identifier, '@')) {
            return self::TYPE_EMAIL;
        }
        if (preg_match(self::E164_PATTERN, $identifier) === 1) {
            return self::TYPE_PHONE;
        }

        return self::TYPE_USERNAME;
    }

    public static function resolve(Environment $env, string $identifier): ?User
    {
        $type = self::detect($identifier);

        return match ($type) {
            self::TYPE_EMAIL => self::byEmail($env, $identifier),
            self::TYPE_PHONE => self::byPhone($env, $identifier),
            self::TYPE_USERNAME => self::byUsername($env, $identifier),
        };
    }

    private static function byEmail(Environment $env, string $identifier): ?User
    {
        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('email_address', strtolower($identifier))
            ->first();

        return $email === null
            ? null
            : User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first();
    }

    private static function byPhone(Environment $env, string $identifier): ?User
    {
        $phone = PhoneNumber::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('phone_number', $identifier)
            ->whereNotNull('verified_at')
            ->first();

        return $phone === null
            ? null
            : User::query()->withoutGlobalScopes()->where('id', $phone->user_id)->first();
    }

    private static function byUsername(Environment $env, string $identifier): ?User
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('username', $identifier)
            ->first();
    }
}
