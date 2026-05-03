<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\ErrorCodes;
use App\Models\EmailAddress;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Services\Tickets\TicketVerifier;

/**
 * Redeems an `__authn_ticket` JWT (PLAN §9.7 / §9.8).
 *
 * Single-use: the verifier guards replay via the jti cache.
 *
 * Sub claim shape per purpose:
 *   - invitation / organization_invitation: sub = email address
 *   - sign_in_token / actor_token: sub = user_id
 *   - waitlist_invite: sub = email address
 *
 * v0.1 supports the email-and-user paths for sign-in (sign_in_token,
 * invitation that resolves to an existing user). Other purposes are
 * relevant to sign-up (AU-10) — we let the controller transfer them
 * over by returning an `unknown_purpose_for_sign_in` failure here.
 */
final class TicketStrategy implements Strategy
{
    public function __construct(private readonly TicketVerifier $verifier) {}

    public function name(): string
    {
        return Verification::STRATEGY_TICKET;
    }

    public function requiresPrepare(): bool
    {
        return false;
    }

    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        return StrategyResult::fail($attempt, ErrorCodes::PREPARE_NOT_REQUIRED, 'Ticket strategy does not need a prepare step.', 422);
    }

    public function attempt(SignInAttempt $attempt, array $params): StrategyResult
    {
        $ticket = $params['ticket'] ?? null;
        if (! is_string($ticket) || $ticket === '') {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_PARAM_NIL, 'ticket is required.');
        }

        $claims = $this->verifier->verify($ticket, $attempt->environment);
        if ($claims === null) {
            return StrategyResult::fail($attempt, ErrorCodes::TICKET_INVALID, 'Ticket is invalid, expired, or already redeemed.', 401);
        }

        $purpose = $claims['purpose'] ?? null;
        $sub = $claims['sub'] ?? null;
        if (! is_string($purpose) || ! is_string($sub)) {
            return StrategyResult::fail($attempt, ErrorCodes::TICKET_INVALID, 'Ticket is missing required claims.', 422);
        }

        $user = match ($purpose) {
            'sign_in_token', 'actor_token' => $this->resolveUserById($sub, $attempt->environment_id),
            'invitation', 'organization_invitation', 'waitlist_invite' => $this->resolveUserByEmail($sub, $attempt->environment_id),
            default => null,
        };

        if ($user === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'No user matches this ticket.', 422);
        }

        if ($user->banned) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_BANNED, 'This user is banned.', 403);
        }
        if ($user->locked && $user->lockout_expires_at?->isFuture()) {
            return StrategyResult::fail($attempt, ErrorCodes::USER_LOCKED, 'This user is temporarily locked.', 403);
        }

        // Stamp the identifier so the rest of the controller has it.
        if ($attempt->identifier === null) {
            $primary = $user->primary_email_address_id
                ? EmailAddress::query()->withoutGlobalScopes()->where('id', $user->primary_email_address_id)->first()
                : null;
            if ($primary !== null) {
                $attempt->forceFill(['identifier' => $primary->email_address])->save();
            }
        }

        return StrategyResult::ok($attempt, $user);
    }

    private function resolveUserById(string $userId, string $environmentId): ?User
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('id', $userId)
            ->where('environment_id', $environmentId)
            ->first();
    }

    private function resolveUserByEmail(string $email, string $environmentId): ?User
    {
        $row = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $environmentId)
            ->where('email_address', strtolower($email))
            ->first();

        return $row ? User::query()->withoutGlobalScopes()->where('id', $row->user_id)->first() : null;
    }
}
