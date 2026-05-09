<?php

declare(strict_types=1);

namespace App\Auth\SignUp;

/**
 * Email normalisation per the per-env user_settings flags (PLAN §13.2):
 *
 *   - block_email_subaddresses             (default false) — strip `+tag`
 *   - ignore_dots_for_gmail_addresses      (default true)  — `f.o.o@gmail.com`
 *                                                            == `foo@gmail.com`
 *
 * Used both at sign-up time (collision detection) and at sign-in /
 * /v1/me/email-addresses CRUD time so the same canonical form is what we
 * uniqueness-check against.
 */
final class IdentifierNormalizer
{
    private const GMAIL_HOSTS = ['gmail.com', 'googlemail.com'];

    /**
     * Returns the canonical form used for uniqueness checks. The original
     * (un-normalised) form is what we store on the EmailAddress row when the
     * env permits it; the canonical form is what we look up against.
     *
     * @param  array<string, mixed>  $userSettings  the env's user_settings blob
     */
    public function canonicalize(string $email, array $userSettings = []): string
    {
        $email = strtolower(trim($email));
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($this->flag($userSettings, 'block_email_subaddresses', false) && str_contains($local, '+')) {
            $local = explode('+', $local, 2)[0];
        }

        if ($this->flag($userSettings, 'ignore_dots_for_gmail_addresses', true)
            && in_array($domain, self::GMAIL_HOSTS, true)
        ) {
            $local = str_replace('.', '', $local);
        }

        return $local.'@'.$domain;
    }

    private function flag(array $userSettings, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $userSettings)) {
            return $default;
        }

        return (bool) $userSettings[$key];
    }
}
