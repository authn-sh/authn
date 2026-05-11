<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Strategies\BackupCodeStrategy;
use App\Auth\Strategies\EmailCodeStrategy;
use App\Auth\Strategies\EmailLinkStrategy;
use App\Auth\Strategies\OauthRedirectStrategy;
use App\Auth\Strategies\PasskeyStrategy;
use App\Auth\Strategies\PasswordStrategy;
use App\Auth\Strategies\PhoneCodeStrategy;
use App\Auth\Strategies\ResetPasswordEmailCodeStrategy;
use App\Auth\Strategies\Strategy;
use App\Auth\Strategies\TicketStrategy;
use App\Auth\Strategies\TotpStrategy;
use App\Models\Verification;
use InvalidArgumentException;

/**
 * Maps strategy names from the wire to their concrete Strategy
 * implementations. Anything not in the whitelist throws.
 */
final class StrategyResolver
{
    public function __construct(
        private readonly PasswordStrategy $password,
        private readonly EmailCodeStrategy $emailCode,
        private readonly EmailLinkStrategy $emailLink,
        private readonly ResetPasswordEmailCodeStrategy $resetPasswordEmailCode,
        private readonly TicketStrategy $ticket,
        private readonly TotpStrategy $totp,
        private readonly BackupCodeStrategy $backupCode,
        private readonly PhoneCodeStrategy $phoneCode,
        private readonly OauthRedirectStrategy $oauth,
        private readonly PasskeyStrategy $passkey,
    ) {}

    public function resolve(string $name): Strategy
    {
        if (preg_match(Verification::OAUTH_STRATEGY_PATTERN, $name) === 1) {
            return $this->oauth;
        }

        return match ($name) {
            Verification::STRATEGY_PASSWORD => $this->password,
            Verification::STRATEGY_EMAIL_CODE => $this->emailCode,
            Verification::STRATEGY_EMAIL_LINK => $this->emailLink,
            Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE => $this->resetPasswordEmailCode,
            Verification::STRATEGY_TICKET => $this->ticket,
            Verification::STRATEGY_TOTP => $this->totp,
            Verification::STRATEGY_BACKUP_CODE => $this->backupCode,
            Verification::STRATEGY_PHONE_CODE => $this->phoneCode,
            Verification::STRATEGY_PASSKEY => $this->passkey,
            default => throw new InvalidArgumentException("Strategy {$name} is not supported."),
        };
    }
}
