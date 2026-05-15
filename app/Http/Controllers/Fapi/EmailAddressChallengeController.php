<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Http\Controllers\Fapi\Concerns\ManagesChallenges;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\ClientResource;
use App\Jobs\Mail\SendVerificationEmail;
use App\Models\Challenge;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Session;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Services\Verification\VerificationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Challenge sub-resource for EmailAddress parents.
 *
 *   POST   /v1/me/email-addresses/{eid}/challenges                   — issue
 *   POST   /v1/me/email-addresses/{eid}/challenges/{cid}/answer      — submit
 *   GET    /v1/me/email-addresses/{eid}/challenges/{cid}             — poll
 */
final class EmailAddressChallengeController
{
    use ManagesChallenges;

    public function __construct(
        private readonly VerificationManager $verifications,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->bareError(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot verify emails.', null);
        }
        $email = $this->loadEmailForUser($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }

        $strategyName = (string) $request->input('strategy', '');
        if ($strategyName === '') {
            return $this->bareError(422, ErrorCodes::FORM_PARAM_NIL, 'strategy is required.', null);
        }
        if ($strategyName !== Verification::STRATEGY_EMAIL_CODE) {
            return $this->bareError(422, ErrorCodes::STRATEGY_NOT_SUPPORTED, "strategy {$strategyName} is not supported on email-address challenges.", null);
        }

        $challenge = $this->issueEmailCodeForEmailAddress($email);

        // Inline answer-on-create — the SDK can pass `code` alongside the
        // create call to skip the second round trip.
        $code = $request->input('code');
        if (is_string($code) && $code !== '') {
            return $this->runEmailAddressAnswer($email, $challenge, ['code' => $code]);
        }

        return $this->emailAddressEnvelope($challenge->fresh());
    }

    public function answer(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->bareError(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot verify emails.', null);
        }
        $email = $this->loadEmailForUser($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }
        $challenge = $this->loadChallenge((string) $request->route('cid'), $email, Challenge::PARENT_EMAIL_ADDRESS);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        return $this->runEmailAddressAnswer($email, $challenge, [
            'code' => $request->input('code'),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $email = $this->loadEmailForUser($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }
        $challenge = $this->loadChallenge((string) $request->route('cid'), $email, Challenge::PARENT_EMAIL_ADDRESS);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        $this->refreshChallengeFromVerification($challenge);

        return response()->json(ChallengeResource::from($challenge->fresh()))
            ->header('Cache-Control', 'no-store');
    }

    private function issueEmailCodeForEmailAddress(EmailAddress $email): Challenge
    {
        $verification = $this->verifications->start(
            $email,
            Verification::STRATEGY_EMAIL_CODE,
            self::SIGN_UP_EMAIL_VERIFICATION_TTL,
        );
        $code = $this->verifications->mintNumericCode(
            $verification,
            VerificationCode::PURPOSE_EMAIL_CODE,
            self::SIGN_UP_EMAIL_VERIFICATION_TTL,
        );

        SendVerificationEmail::dispatch(
            $email->environment_id,
            $email->email_address,
            $code,
            VerificationCode::PURPOSE_EMAIL_CODE,
            $verification->id,
            $email->id,
        );

        return $this->createChallenge(
            $email,
            Challenge::PARENT_EMAIL_ADDRESS,
            Challenge::STEP_SINGLE,
            Verification::STRATEGY_EMAIL_CODE,
            $verification,
        );
    }

    private function runEmailAddressAnswer(EmailAddress $email, Challenge $challenge, array $params): JsonResponse
    {
        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return $this->bareError(422, ErrorCodes::VERIFICATION_FAILED, 'Underlying verification missing.', null);
        }

        $code = $params['code'] ?? null;
        if (! is_string($code) || $code === '') {
            return $this->bareError(422, ErrorCodes::FORM_PARAM_NIL, 'code is required.', null);
        }

        $ok = $this->verifications->attempt($verification, $code);
        if (! $ok) {
            $fresh = $verification->fresh();
            $errCode = $fresh->status === Verification::STATUS_FAILED
                ? ErrorCodes::VERIFICATION_FAILED
                : ($fresh->status === Verification::STATUS_EXPIRED
                    ? ErrorCodes::VERIFICATION_EXPIRED
                    : ErrorCodes::FORM_CODE_INCORRECT);
            $this->reflectFailure($challenge, $fresh, $errCode, 'Incorrect code.');

            return $this->bareError(422, $errCode, 'Incorrect code.', null);
        }

        $this->markChallengeVerified($challenge, $verification->fresh() ?? $verification);
        $email->forceFill(['verified_at' => now()])->save();

        return $this->emailAddressEnvelope($challenge->fresh());
    }

    private function emailAddressEnvelope(?Challenge $challenge): JsonResponse
    {
        $client = app()->bound(Client::class) ? app(Client::class) : null;

        return response()->json([
            'response' => ChallengeResource::from($challenge),
            'client' => $client !== null ? ClientResource::from($client->fresh()) : null,
        ])->header('Cache-Control', 'no-store');
    }

    private function loadEmailForUser(Request $request): EmailAddress|JsonResponse
    {
        $eid = (string) $request->route('email_address_id');
        $user = app(User::class);
        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('id', $eid)
            ->where('user_id', $user->id)
            ->first();
        if ($email === null) {
            return $this->bareError(404, ErrorCodes::EMAIL_NOT_FOUND, 'Email not found on this user.', null);
        }

        return $email;
    }
}
