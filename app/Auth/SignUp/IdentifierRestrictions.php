<?php

declare(strict_types=1);

namespace App\Auth\SignUp;

use App\Models\AllowlistIdentifier;
use App\Models\BlocklistIdentifier;
use App\Models\Environment;

/**
 * Resolves whether a given email is allowed to sign up against an env.
 *
 * Order:
 *   1. block_disposable_email_domains (uses a small bundled list — full
 *      catalogue lands in AU-18)
 *   2. blocklist_identifiers (any match → reject)
 *   3. signup_mode = restricted → must match an allowlist row
 */
final class IdentifierRestrictions
{
    /**
     * Tiny seed list. AU-18 swaps in the full disposable-domain catalogue.
     */
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com',
        'tempmail.com',
        'guerrillamail.com',
        '10minutemail.com',
        'throwawaymail.com',
        'yopmail.com',
        'trashmail.com',
        'getnada.com',
        'sharklasers.com',
    ];

    public const REASON_DISPOSABLE = 'form_identifier_disposable';

    public const REASON_NOT_ALLOWED = 'form_identifier_not_allowed';

    public function check(Environment $environment, string $email): ?string
    {
        $email = strtolower($email);
        if (! str_contains($email, '@')) {
            return null;
        }
        [, $domain] = explode('@', $email, 2);

        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];
        if (($userSettings['block_disposable_email_domains'] ?? false) === true
            && in_array($domain, self::DISPOSABLE_DOMAINS, true)
        ) {
            return self::REASON_DISPOSABLE;
        }

        $blocked = BlocklistIdentifier::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $environment->id)
            ->where(function ($q) use ($email, $domain): void {
                $q->where(function ($qq) use ($email): void {
                    $qq->where('identifier_type', BlocklistIdentifier::TYPE_EMAIL_ADDRESS)
                        ->where('identifier', $email);
                })->orWhere(function ($qq) use ($domain): void {
                    $qq->where('identifier_type', BlocklistIdentifier::TYPE_EMAIL_DOMAIN)
                        ->whereIn('identifier', [$domain, '@'.$domain]);
                });
            })
            ->exists();
        if ($blocked) {
            return self::REASON_NOT_ALLOWED;
        }

        if ($environment->signup_mode === Environment::SIGNUP_MODE_RESTRICTED) {
            $allowed = AllowlistIdentifier::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $environment->id)
                ->where(function ($q) use ($email, $domain): void {
                    $q->where(function ($qq) use ($email): void {
                        $qq->where('identifier_type', AllowlistIdentifier::TYPE_EMAIL_ADDRESS)
                            ->where('identifier', $email);
                    })->orWhere(function ($qq) use ($domain): void {
                        $qq->where('identifier_type', AllowlistIdentifier::TYPE_EMAIL_DOMAIN)
                            ->whereIn('identifier', [$domain, '@'.$domain]);
                    });
                })
                ->exists();
            if (! $allowed) {
                return self::REASON_NOT_ALLOWED;
            }
        }

        return null;
    }
}
