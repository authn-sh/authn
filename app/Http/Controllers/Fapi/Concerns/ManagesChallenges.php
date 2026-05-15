<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi\Concerns;

use App\Auth\ErrorCodes;
use App\Http\Resources\ClientResource;
use App\Models\Challenge;
use App\Models\Client;
use App\Models\Session;
use App\Models\Verification;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Shared challenge plumbing used by the per-parent challenge controllers
 * (SignInChallengeController, SignUpChallengeController,
 * EmailAddressChallengeController). The Verification still owns the
 * cryptographic state; the Challenge is the API-facing veneer that exposes
 * the lifecycle in a shape that's identical across strategies.
 */
trait ManagesChallenges
{
    private const SIGN_UP_EMAIL_VERIFICATION_TTL = 600;

    private const SESSION_COOKIE_TTL_SECONDS = 86400;

    private function createChallenge(
        Model $attempt,
        string $parentType,
        string $step,
        string $strategy,
        Verification $verification,
    ): Challenge {
        return DB::transaction(function () use ($attempt, $parentType, $step, $strategy, $verification): Challenge {
            $challenge = Challenge::query()->withoutGlobalScopes()->create([
                'environment_id' => $attempt->environment_id,
                'parent_type' => $parentType,
                'parent_id' => $attempt->getKey(),
                'step' => $step,
                'strategy' => $strategy,
                'status' => $this->mapVerificationStatus($verification->status),
                'verification_id' => $verification->id,
                'attempts' => (int) $verification->attempts,
                'nonce' => $verification->nonce,
                'external_verification_redirect_url' => $verification->external_verification_redirect_url,
                'error_code' => $verification->error_code,
                'error_message' => $verification->error_message,
                'expire_at' => $verification->expire_at,
            ]);

            $attempt->forceFill(['current_challenge_id' => $challenge->id])->save();

            return $challenge;
        });
    }

    private function refreshChallengeFromVerification(Challenge $challenge): void
    {
        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return;
        }
        $challenge->forceFill([
            'status' => $this->mapVerificationStatus($verification->status),
            'attempts' => (int) $verification->attempts,
            'nonce' => $verification->nonce,
            'external_verification_redirect_url' => $verification->external_verification_redirect_url,
            'error_code' => $verification->error_code,
            'error_message' => $verification->error_message,
        ])->save();
    }

    private function reflectFailure(Challenge $challenge, ?Verification $verification, ?string $errorCode, ?string $errorMessage): void
    {
        $update = [
            'attempts' => $verification !== null ? (int) $verification->attempts : (int) $challenge->attempts + 1,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
        if ($verification !== null) {
            $update['status'] = $this->mapVerificationStatus($verification->status);
        }
        $challenge->forceFill($update)->save();

        if ($verification !== null && $verification->status !== Verification::STATUS_UNVERIFIED) {
            $this->clearParentCurrentChallenge($challenge);
        }
    }

    private function markChallengeVerified(Challenge $challenge, Verification $verification): void
    {
        $challenge->forceFill([
            'status' => Challenge::STATUS_VERIFIED,
            'attempts' => (int) $verification->attempts,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $this->clearParentCurrentChallenge($challenge);
    }

    /**
     * Clears `current_challenge_id` on the parent SignIn / SignUp once
     * the challenge has left `pending`. Keeps the SDK polling story
     * unambiguous: `current_challenge_id` always points at a live
     * challenge worth interacting with, never at a terminal one.
     */
    private function clearParentCurrentChallenge(Challenge $challenge): void
    {
        $parentClass = Challenge::MORPH_MAP[$challenge->parent_type] ?? null;
        if ($parentClass === null) {
            return;
        }
        /** @var class-string<Model> $parentClass */
        $parentClass::query()
            ->withoutGlobalScopes()
            ->where('id', $challenge->parent_id)
            ->where('current_challenge_id', $challenge->id)
            ->update(['current_challenge_id' => null]);
    }

    private function mapVerificationStatus(string $status): string
    {
        return match ($status) {
            Verification::STATUS_UNVERIFIED => Challenge::STATUS_PENDING,
            Verification::STATUS_VERIFIED => Challenge::STATUS_VERIFIED,
            Verification::STATUS_TRANSFERABLE => Challenge::STATUS_TRANSFERABLE,
            Verification::STATUS_FAILED => Challenge::STATUS_FAILED,
            Verification::STATUS_EXPIRED => Challenge::STATUS_EXPIRED,
            default => Challenge::STATUS_PENDING,
        };
    }

    private function loadChallenge(string $cid, Model $attempt, string $expectedParentType): Challenge|JsonResponse
    {
        $challenge = Challenge::query()
            ->withoutGlobalScopes()
            ->where('id', $cid)
            ->where('parent_type', $expectedParentType)
            ->where('parent_id', $attempt->getKey())
            ->first();
        if ($challenge === null) {
            return $this->bareError(404, ErrorCodes::VERIFICATION_FAILED, 'Challenge not found.', app(Client::class));
        }

        return $challenge;
    }

    private function bareError(int $status, string $code, string $message, ?Client $client): JsonResponse
    {
        $body = [
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ];
        if ($client !== null) {
            $body['client'] = ClientResource::from($client->fresh());
        }

        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

    private function buildSessionCookie(Session $newSession): Cookie
    {
        $minted = app(SessionTokenIssuer::class)->mint($newSession, lifetimeOverride: self::SESSION_COOKIE_TTL_SECONDS);

        return \Illuminate\Support\Facades\Cookie::make(
            name: '__session',
            value: (string) $minted['jwt'],
            minutes: (int) ceil(self::SESSION_COOKIE_TTL_SECONDS / 60),
            path: '/',
            domain: null,
            secure: request()->secure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }
}
