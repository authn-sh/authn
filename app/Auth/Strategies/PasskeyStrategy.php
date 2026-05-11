<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Auth\ErrorCodes;
use App\Auth\Passkey\Exceptions\PasskeyException;
use App\Auth\Passkey\PasskeyService;
use App\Models\Challenge;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Passkey;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Settings\PasskeySettings;
use Illuminate\Container\Container;

/**
 * First-factor passkey strategy. `requiresPrepare()` is true — the begin
 * call builds the WebAuthn `requestOptions` and stashes the challenge
 * bytes on the Challenge row; the answer call carries the
 * authenticator's assertion.
 *
 * Per the v0.3 strict-semantic MFA contract: the env-level
 * `authentication_strategies.passkey.enabled` toggle gates *new
 * enrolment* (AU-3 honours it). Existing passkeys remain usable for
 * sign-in regardless of the toggle — `applicableForFirstFactor` keys
 * solely off "does the resolved user have ≥1 verified passkey".
 */
final class PasskeyStrategy implements Strategy
{
    public function __construct(private readonly PasskeyService $service) {}

    public function name(): string
    {
        return Verification::STRATEGY_PASSKEY;
    }

    public function requiresPrepare(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function prepare(SignInAttempt $attempt, array $params): StrategyResult
    {
        $user = $this->resolveUser($attempt);
        if ($user === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'User not found for this attempt.', 422);
        }
        if (! $this->userHasVerifiedPasskeys($user)) {
            return StrategyResult::fail($attempt, ErrorCodes::STRATEGY_NOT_SUPPORTED, 'Resolved user has no verified passkeys.', 422);
        }

        $challenge = $params['challenge'] ?? null;
        $verification = $params['verification'] ?? null;
        if (! $challenge instanceof Challenge || ! $verification instanceof Verification) {
            return StrategyResult::fail($attempt, ErrorCodes::VERIFICATION_FAILED, 'Missing challenge / verification context.', 422);
        }

        $env = $this->environmentFor($attempt);
        $options = $this->service->buildAuthenticationOptions($env, $challenge->refresh(), $user);

        $metadata = is_array($challenge->metadata) ? $challenge->metadata : [];
        $metadata['passkey_request_options'] = $options;
        $challenge->forceFill(['metadata' => $metadata])->save();

        return StrategyResult::ok($attempt, $user, $verification);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function attempt(SignInAttempt $attempt, array $params): StrategyResult
    {
        $assertion = $params['assertion'] ?? null;
        if (! is_array($assertion)) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_PARAM_NIL, 'assertion is required.');
        }

        $user = $this->resolveUser($attempt);
        if ($user === null) {
            return StrategyResult::fail($attempt, ErrorCodes::FORM_IDENTIFIER_NOT_FOUND, 'User not found for this attempt.', 422);
        }

        $challenge = $params['challenge'] ?? null;
        $verification = $params['verification'] ?? null;
        if (! $challenge instanceof Challenge || ! $verification instanceof Verification) {
            return StrategyResult::fail($attempt, ErrorCodes::VERIFICATION_FAILED, 'Missing challenge / verification context.', 422);
        }

        $env = $this->environmentFor($attempt);

        try {
            $this->service->verifyAssertion($env, $challenge, $user, $assertion);
        } catch (PasskeyException $e) {
            return StrategyResult::fail($attempt, $e->code(), $e->getMessage());
        }

        $verification->forceFill([
            'status' => Verification::STATUS_VERIFIED,
            'verified_at' => now(),
        ])->save();

        return StrategyResult::ok($attempt, $user, $verification->fresh() ?? $verification);
    }

    /**
     * Whether `passkey` should appear in `supported_strategies` for an
     * in-flight SignIn attempt — i.e. whether the resolved user holds at
     * least one verified passkey. Used by `SignInResource` and by the
     * ChallengeController's strategy-validation gate.
     */
    public function applicableForFirstFactor(SignInAttempt $attempt): bool
    {
        $user = $this->resolveUser($attempt);
        if ($user === null) {
            return false;
        }

        return $this->userHasVerifiedPasskeys($user);
    }

    /**
     * Whether the env-level `passkey.enabled` toggle is on. Used by the
     * registration surface (AU-3) and the dashboard wizard. Sign-in flows
     * deliberately do *not* consult this — see the class docblock.
     */
    public function enrolmentEnabled(Environment $environment): bool
    {
        return PasskeySettings::fromUserSettings(
            is_array($environment->user_settings) ? $environment->user_settings : [],
        )->enabled;
    }

    private function userHasVerifiedPasskeys(User $user): bool
    {
        return Passkey::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->exists();
    }

    private function resolveUser(SignInAttempt $attempt): ?User
    {
        if ($attempt->identifier === null) {
            return null;
        }
        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $attempt->environment_id)
            ->where('email_address', strtolower((string) $attempt->identifier))
            ->first();
        if ($email === null) {
            return null;
        }

        return User::query()->withoutGlobalScopes()->where('id', $email->user_id)->first();
    }

    private function environmentFor(SignInAttempt $attempt): Environment
    {
        $container = Container::getInstance();
        if ($container->bound(Environment::class)) {
            $env = $container->make(Environment::class);
            if ($env instanceof Environment && $env->id === $attempt->environment_id) {
                return $env;
            }
        }

        return Environment::query()->where('id', $attempt->environment_id)->firstOrFail();
    }
}
