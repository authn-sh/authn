<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Auth\TestMode\Detector;
use App\Models\Environment;
use App\Models\Verification;
use App\Models\VerificationCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates Verification + VerificationCode lifecycle. Used by:
 *   - AU-9 sign-in flow (start a new email_code, attempt to verify it)
 *   - AU-10 sign-up flow (start an email verification on the staged email)
 *   - AU-12 /v1/me/email-addresses/{id}/prepare-verification etc.
 *   - AU-19's reaper for the `expired` transition.
 */
final class VerificationManager
{
    /**
     * Default max wrong attempts before a Verification flips to `failed`.
     * Matches PLAN §9.5.
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    public function __construct(private readonly CodeGenerator $codeGenerator) {}

    /**
     * Open a fresh Verification on the supplied owner. The caller is
     * responsible for separately creating any cleartext code (via
     * `mintNumericCode` below) and dispatching the appropriate email/SMS
     * job — VerificationManager only owns the state machine.
     */
    public function start(Model $owner, string $strategy, int $ttlSeconds, ?string $purpose = null): Verification
    {
        if (! in_array($strategy, Verification::STRATEGIES, true)) {
            throw new InvalidArgumentException("Strategy {$strategy} is not supported.");
        }

        $environmentId = $this->resolveEnvironmentId($owner);

        return Verification::query()->withoutGlobalScopes()->create([
            'environment_id' => $environmentId,
            'verifiable_type' => $owner->getMorphClass(),
            'verifiable_id' => $owner->getKey(),
            'strategy' => $strategy,
            'status' => Verification::STATUS_UNVERIFIED,
            'attempts' => 0,
            'was_test' => $this->shouldTreatAsTest($owner, $environmentId),
            'expire_at' => now()->addSeconds($ttlSeconds),
        ]);
    }

    /**
     * Mint a numeric OTP, persist its sha256 alongside the Verification,
     * and return the cleartext for the caller to send. The cleartext is
     * never stored.
     */
    public function mintNumericCode(Verification $verification, string $purpose, int $ttlSeconds): string
    {
        // Test-mode shortcut (PLAN §9.11): when the verification was opened
        // against a reserved test identifier in a test-mode-enabled env, mint
        // the canonical 424242. Still hashed and stored — the verifier checks
        // the hash, not a hardcoded constant elsewhere.
        $cleartext = $verification->was_test
            ? Detector::FIXED_OTP
            : $this->codeGenerator->generateNumericCode();

        VerificationCode::query()->create([
            'verification_id' => $verification->id,
            'code_hash' => $this->codeGenerator->hash($cleartext),
            'purpose' => $purpose,
            'expires_at' => now()->addSeconds($ttlSeconds),
            'created_at' => now(),
        ]);

        return $cleartext;
    }

    /**
     * Attempt to redeem the latest unconsumed code on the Verification.
     *
     * Returns true on success (the Verification is marked verified, the
     * code is consumed). On a wrong code, increments `attempts` and flips
     * to `failed` on hitting `maxAttempts`. Returns false in both wrong-
     * code and expired-code branches; the caller branches on
     * `$verification->status` afterwards.
     */
    public function attempt(
        Verification $verification,
        string $candidate,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
    ): bool {
        if ($verification->status !== Verification::STATUS_UNVERIFIED) {
            return false;
        }

        if ($verification->isExpired()) {
            $verification->forceFill([
                'status' => Verification::STATUS_EXPIRED,
                'error_code' => 'verification_expired',
            ])->save();

            return false;
        }

        $candidateHash = $this->codeGenerator->hash($candidate);

        return DB::transaction(function () use ($verification, $candidateHash, $maxAttempts): bool {
            $code = $verification->codes()
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($code === null) {
                $verification->forceFill([
                    'status' => Verification::STATUS_EXPIRED,
                    'error_code' => 'verification_expired',
                ])->save();

                return false;
            }

            if (! hash_equals($code->code_hash, $candidateHash)) {
                $attempts = $verification->attempts + 1;
                $update = ['attempts' => $attempts];

                if ($attempts >= $maxAttempts) {
                    $update['status'] = Verification::STATUS_FAILED;
                    $update['error_code'] = 'verification_failed';
                } else {
                    $update['error_code'] = 'form_code_incorrect';
                }
                $verification->forceFill($update)->save();

                return false;
            }

            $now = now();
            $code->forceFill(['consumed_at' => $now])->save();
            $verification->forceFill([
                'status' => Verification::STATUS_VERIFIED,
                'verified_at' => $now,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            return true;
        });
    }

    public function markVerified(Verification $verification, ?string $byClientId = null): void
    {
        $verification->forceFill([
            'status' => Verification::STATUS_VERIFIED,
            'verified_at' => now(),
            'redeemed_by_client_id' => $byClientId,
        ])->save();
    }

    /**
     * True when the verification's owner identifier is a reserved test
     * pattern AND the env has test_mode = enabled. Mirrors the policy in
     * App\Auth\TestMode\Policy (which the controllers consult before us).
     */
    private function shouldTreatAsTest(Model $owner, string $environmentId): bool
    {
        $identifier = $this->extractIdentifier($owner);
        if ($identifier === null || ! Detector::isTestIdentifier($identifier)) {
            return false;
        }
        $env = Environment::query()->withoutGlobalScopes()->where('id', $environmentId)->first();
        if ($env === null) {
            return false;
        }

        return $env->testMode() === Environment::TEST_MODE_ENABLED;
    }

    private function extractIdentifier(Model $owner): ?string
    {
        if (isset($owner->email_address) && is_string($owner->email_address)) {
            return $owner->email_address;
        }
        if (isset($owner->identifier) && is_string($owner->identifier)) {
            return $owner->identifier;
        }
        if (isset($owner->phone_number) && is_string($owner->phone_number)) {
            return $owner->phone_number;
        }

        return null;
    }

    private function resolveEnvironmentId(Model $owner): string
    {
        if (isset($owner->environment_id) && is_string($owner->environment_id)) {
            return $owner->environment_id;
        }

        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        if ($env instanceof Environment) {
            return $env->id;
        }

        throw new InvalidArgumentException(
            'VerificationManager::start needs an environment_id. Either pass an owner with one, or bind Environment into the container.'
        );
    }
}
