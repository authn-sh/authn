<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\ErrorCodes;
use App\Jobs\Mail\SendMagicLinkEmail;
use App\Models\EmailAddress;
use App\Models\EmailTemplate;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Services\MagicLink\MagicLinkIssuer;
use App\Services\Verification\VerificationManager;

/**
 * email_link first-factor for sign-in (AU-10). The originating device
 * polls the SignInAttempt; the click device hits MagicLinkController
 * which flips the Verification.status to verified, and the next poll
 * promotes the attempt to complete.
 *
 * prepare:
 *   - Resolves the EmailAddress (by id or by attempt.identifier).
 *   - Starts a Verification(strategy=email_link, TTL 5m) attached to
 *     the SignInAttempt itself so the click handler can resolve back
 *     to the attempt without an extra lookup.
 *   - Mints the magic-link JWT + persists its hash on a VerificationCode.
 *   - Dispatches SendMagicLinkEmail.
 *
 * attempt:
 *   - Returns success only when the polled Verification.status flipped
 *     to verified. The actual flip happens out-of-band when the user
 *     clicks the link.
 */
final class EmailLinkStrategy implements Strategy
{
    public const TTL_SECONDS = MagicLinkIssuer::TTL_SECONDS;

    public function __construct(
        private readonly VerificationManager $verifications,
        private readonly MagicLinkIssuer $issuer,
    ) {}

    public function name(): string
    {
        return Verification::STRATEGY_EMAIL_LINK;
    }

    public function requiresPrepare(): bool
    {
        return true;
    }

    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        $emailAddress = $this->resolveEmailAddress($attempt, $params);
        if ($emailAddress === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'No email address matches this identifier.', 422);
        }

        // Open the Verification on the SignInAttempt itself — the click
        // handler reads the verifiable to resolve the originating attempt
        // without needing the EmailAddress id again.
        $verification = $this->verifications->start(
            $attempt,
            Verification::STRATEGY_EMAIL_LINK,
            self::TTL_SECONDS,
        );

        $minted = $this->issuer->issue($verification, isset($params['redirect_url']) && is_string($params['redirect_url']) ? $params['redirect_url'] : null);

        SendMagicLinkEmail::dispatch(
            $attempt->environment_id,
            $emailAddress->email_address,
            $minted['url'],
            EmailTemplate::SLUG_MAGIC_LINK_SIGN_IN,
            $verification->id,
            $emailAddress->id,
        );

        $verification->forceFill(['external_verification_redirect_url' => $minted['url']])->save();

        return StrategyResult::ok($attempt, verification: $verification->fresh() ?? $verification);
    }

    public function attempt(SignInAttempt $attempt, array $params): StrategyResult
    {
        $verification = $params['verification'] ?? null;
        if (! $verification instanceof Verification) {
            return StrategyResult::fail($attempt, ErrorCodes::VERIFICATION_FAILED, 'No magic link is in flight for this attempt.', 422);
        }

        if ($verification->status === Verification::STATUS_UNVERIFIED) {
            return StrategyResult::fail($attempt, ErrorCodes::VERIFICATION_FAILED, 'Magic link not yet redeemed.', 422, $verification);
        }
        if ($verification->status === Verification::STATUS_EXPIRED) {
            return StrategyResult::fail($attempt, ErrorCodes::VERIFICATION_EXPIRED, 'Magic link expired.', 422, $verification);
        }
        if ($verification->status !== Verification::STATUS_VERIFIED) {
            return StrategyResult::fail($attempt, ErrorCodes::VERIFICATION_FAILED, "Magic link verification is in status {$verification->status}.", 422, $verification);
        }

        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('email_address', strtolower((string) $attempt->identifier))
            ->first();
        $user = $email !== null
            ? User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first()
            : null;
        if ($user === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'User not found.', 422);
        }
        if ($user->banned) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_BANNED, 'This user is banned.', 403);
        }
        if ($user->locked && $user->lockout_expires_at?->isFuture()) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_LOCKED, 'This user is temporarily locked.', 403);
        }

        return StrategyResult::ok($attempt, $user, $verification);
    }

    private function resolveEmailAddress(SignInAttempt $attempt, array $params): ?EmailAddress
    {
        $emailAddressId = $params['email_address_id'] ?? null;
        if (is_string($emailAddressId) && $emailAddressId !== '') {
            return EmailAddress::query()
                ->withoutGlobalScopes()
                ->where('id', $emailAddressId)
                ->where('environment_id', $attempt->environment_id)
                ->first();
        }

        return EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('email_address', strtolower((string) $attempt->identifier))
            ->first();
    }
}
