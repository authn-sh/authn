<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Auth\EnterpriseSso\EnterpriseConnectionService;
use App\Models\BackupCode;
use App\Models\EmailAddress;
use App\Models\EnterpriseConnection;
use App\Models\Passkey;
use App\Models\PhoneNumber;
use App\Models\SignInAttempt;
use App\Models\TotpSecret;
use App\Models\User;
use App\Models\Verification;

/**
 * Mirrors openapi `SignIn`. Factor verification state is no longer
 * inlined — it lives on the Challenge sub-resource. The SignIn exposes
 * the live Challenge id (`current_challenge_id`) plus the strategies the
 * client may issue next, narrowed to its current state.
 */
final class SignInResource
{
    public static function from(?SignInAttempt $attempt): ?array
    {
        if ($attempt === null) {
            return null;
        }

        $transferable = $attempt->status === SignInAttempt::STATUS_TRANSFERABLE;
        $matchedConnection = self::matchedEnterpriseConnection($attempt);

        return [
            'object' => 'sign_in_attempt',
            'id' => $attempt->id,
            'status' => $attempt->status,
            'identifier' => $attempt->identifier,
            'supported_identifiers' => ['email_address'],
            'supported_strategies' => self::supportedStrategies($attempt, $matchedConnection),
            'current_challenge_id' => $attempt->current_challenge_id,
            'user_data' => self::userDataPreview($attempt),
            'created_session_id' => $attempt->created_session_id,
            'abandon_at' => $attempt->abandon_at->getTimestampMs(),
            'transferable_to_signup' => $transferable,
            'target_flow' => $transferable ? 'sign_up' : null,
            'enterprise_connection_id' => $matchedConnection?->id,
        ];
    }

    /**
     * Strategies the client may issue next via `POST /sign-ins/{sid}/challenges`.
     * Narrowed by the SignIn's current status — terminal states return [].
     * AU-8: when the identifier domain routes to exactly one enabled
     * `EnterpriseConnection`, narrow to `[enterprise_sso]` so the SDK
     * jumps to the IdP without showing other options.
     *
     * @return list<string>
     */
    private static function supportedStrategies(SignInAttempt $attempt, ?EnterpriseConnection $matchedConnection): array
    {
        if ($attempt->status === SignInAttempt::STATUS_NEEDS_FIRST_FACTOR && $matchedConnection !== null) {
            return [Verification::STRATEGY_ENTERPRISE_SSO];
        }

        return match ($attempt->status) {
            SignInAttempt::STATUS_NEEDS_FIRST_FACTOR => self::firstFactorStrategies($attempt),
            SignInAttempt::STATUS_NEEDS_SECOND_FACTOR => self::secondFactorStrategies($attempt),
            default => [],
        };
    }

    /**
     * AU-8 domain routing: resolve the env's `EnterpriseConnection` that
     * covers the identifier domain (prefers org-scoped, falls back to
     * instance-wide). Returns `null` when no connection matches.
     */
    private static function matchedEnterpriseConnection(SignInAttempt $attempt): ?EnterpriseConnection
    {
        $env = $attempt->environment;
        if ($env === null || ! is_string($attempt->identifier) || $attempt->identifier === '') {
            return null;
        }

        return app(EnterpriseConnectionService::class)->findByIdentifierDomain($env, $attempt->identifier);
    }

    /**
     * @return list<string>
     */
    private static function firstFactorStrategies(SignInAttempt $attempt): array
    {
        $user = self::resolveUser($attempt);
        if ($user === null) {
            return [];
        }

        $strategies = [];
        if ($user->password_hash !== null) {
            $strategies[] = Verification::STRATEGY_PASSWORD;
        }
        $strategies[] = Verification::STRATEGY_EMAIL_CODE;
        $strategies[] = Verification::STRATEGY_EMAIL_LINK;
        $strategies[] = Verification::STRATEGY_RESET_PASSWORD_EMAIL_CODE;

        $hasPasskey = Passkey::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->exists();
        if ($hasPasskey) {
            $strategies[] = Verification::STRATEGY_PASSKEY;
        }

        return $strategies;
    }

    /**
     * Strict semantic: second-factor availability is purely per-user
     * enrolment. The env-level `multi_factor.{totp,backup_codes}.enabled`
     * toggle gates new enrolments only — operator policy changes never
     * silently downgrade an already-enrolled user.
     *
     * @return list<string>
     */
    private static function secondFactorStrategies(SignInAttempt $attempt): array
    {
        $user = self::resolveUser($attempt);
        if ($user === null) {
            return [];
        }

        $strategies = [];
        $hasTotp = (bool) $user->totp_enabled && TotpSecret::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->exists();
        if ($hasTotp) {
            $strategies[] = Verification::STRATEGY_TOTP;
        }
        $hasUnspent = (bool) $user->backup_code_enabled && BackupCode::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->exists();
        if ($hasUnspent) {
            $strategies[] = Verification::STRATEGY_BACKUP_CODE;
        }

        $hasReservedPhone = PhoneNumber::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->where('reserved_for_second_factor', true)
            ->exists();
        if ($hasReservedPhone) {
            $strategies[] = Verification::STRATEGY_PHONE_CODE;
        }

        return $strategies;
    }

    private static function resolveUser(SignInAttempt $attempt): ?User
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

        return User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function userDataPreview(SignInAttempt $attempt): array
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

        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'image_url' => $user->image_url,
            'has_image' => (bool) $user->has_image,
        ];
    }
}
