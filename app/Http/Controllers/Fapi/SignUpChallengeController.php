<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Auth\SignUp\StageRequirements;
use App\Http\Controllers\Fapi\Concerns\ManagesChallenges;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\SignUpResource;
use App\Jobs\Mail\SendVerificationEmail;
use App\Models\Challenge;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Session;
use App\Models\SignUpAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Services\Domains\DomainEnroller;
use App\Services\Sessions\SessionLifecycle;
use App\Services\Verification\VerificationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Challenge sub-resource for SignUp parents.
 *
 *   POST   /v1/client/sign-ups/{sid}/challenges                — issue
 *   POST   /v1/client/sign-ups/{sid}/challenges/{cid}/answer   — submit
 *   GET    /v1/client/sign-ups/{sid}/challenges/{cid}          — poll
 */
final class SignUpChallengeController
{
    use ManagesChallenges;

    public function __construct(
        private readonly VerificationManager $verifications,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadSignUpAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $strategyName = (string) $request->input('strategy', '');
        if ($strategyName === '') {
            return $this->errorWithSignUp(422, ErrorCodes::FORM_PARAM_NIL, 'strategy is required.', $client, $attempt);
        }
        if (! in_array($strategyName, $this->signUpSupportedStrategies($attempt), true)) {
            return $this->errorWithSignUp(422, ErrorCodes::STRATEGY_NOT_SUPPORTED, "strategy {$strategyName} is not supported on this sign-up.", $client, $attempt);
        }
        if (! is_string($attempt->email_address) || $attempt->email_address === '') {
            return $this->errorWithSignUp(422, ErrorCodes::FORM_PARAM_NIL, 'email_address is required before issuing a challenge.', $client, $attempt);
        }

        $verification = $this->verifications->start($attempt, Verification::STRATEGY_EMAIL_CODE, self::SIGN_UP_EMAIL_VERIFICATION_TTL);
        $code = $this->verifications->mintNumericCode($verification, VerificationCode::PURPOSE_EMAIL_CODE, self::SIGN_UP_EMAIL_VERIFICATION_TTL);

        SendVerificationEmail::dispatch(
            $attempt->environment_id,
            $attempt->email_address,
            $code,
            VerificationCode::PURPOSE_EMAIL_CODE,
            $verification->id,
        );

        $challenge = $this->createChallenge($attempt, Challenge::PARENT_SIGN_UP, Challenge::STEP_SINGLE, Verification::STRATEGY_EMAIL_CODE, $verification);

        return $this->signUpEnvelope($client, $attempt->fresh(), $challenge);
    }

    public function answer(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $cid = (string) $request->route('cid');
        $client = app(Client::class);

        $attempt = $this->loadSignUpAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $challenge = $this->loadChallenge($cid, $attempt, Challenge::PARENT_SIGN_UP);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return $this->errorWithSignUp(422, ErrorCodes::VERIFICATION_FAILED, 'Underlying verification missing.', $client, $attempt);
        }

        $code = $request->input('code');
        if (! is_string($code) || $code === '') {
            return $this->errorWithSignUp(422, ErrorCodes::FORM_PARAM_NIL, 'code is required.', $client, $attempt, $challenge);
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

            return $this->errorWithSignUp(422, $errCode, 'Incorrect code.', $client, $attempt, $challenge->fresh());
        }

        $this->markChallengeVerified($challenge, $verification->fresh() ?? $verification);

        return $this->finalizeSignUpIfReady($client, $attempt, $challenge->fresh());
    }

    public function show(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $cid = (string) $request->route('cid');
        $client = app(Client::class);

        $attempt = $this->loadSignUpAttempt($sid, $client, allowTerminal: true);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $challenge = $this->loadChallenge($cid, $attempt, Challenge::PARENT_SIGN_UP);
        if ($challenge instanceof JsonResponse) {
            return $challenge;
        }

        $this->refreshChallengeFromVerification($challenge);

        return response()->json(ChallengeResource::from($challenge->fresh()))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return list<string>
     */
    private function signUpSupportedStrategies(SignUpAttempt $attempt): array
    {
        $unverified = is_array($attempt->unverified_fields) ? $attempt->unverified_fields : [];
        if (! in_array('email_address', $unverified, true)) {
            return [];
        }

        return [Verification::STRATEGY_EMAIL_CODE];
    }

    private function loadSignUpAttempt(string $sid, Client $client, bool $allowTerminal = false): SignUpAttempt|JsonResponse
    {
        $attempt = SignUpAttempt::query()
            ->withoutGlobalScopes()
            ->where('id', $sid)
            ->where('client_id', $client->id)
            ->first();
        if ($attempt === null) {
            return $this->bareError(404, ErrorCodes::SIGN_UP_NOT_FOUND, 'Sign-up attempt not found on this device.', $client);
        }
        if (! $allowTerminal) {
            if ($attempt->status === SignUpAttempt::STATUS_ABANDONED) {
                return $this->errorWithSignUp(422, ErrorCodes::SIGN_UP_ABANDONED, 'This sign-up attempt has been abandoned.', $client, $attempt);
            }
            if ($attempt->status === SignUpAttempt::STATUS_COMPLETE) {
                return $this->errorWithSignUp(422, ErrorCodes::SIGN_UP_ALREADY_COMPLETE, 'This sign-up attempt is already complete.', $client, $attempt);
            }
        }

        return $attempt;
    }

    private function finalizeSignUpIfReady(Client $client, SignUpAttempt $attempt, ?Challenge $challenge): JsonResponse
    {
        $env = app(Environment::class);

        $stage = app(StageRequirements::class);

        $verifiedFlags = [];
        $emailVerified = $attempt->challenges()
            ->where('status', Challenge::STATUS_VERIFIED)
            ->where('strategy', Verification::STRATEGY_EMAIL_CODE)
            ->exists();
        if ($emailVerified) {
            $verifiedFlags['email_address'] = true;
        }

        $supplied = [
            'email_address' => $attempt->email_address,
            'username' => $attempt->username,
            'first_name' => $attempt->first_name,
            'last_name' => $attempt->last_name,
            'password' => $attempt->password_hash !== null ? '__present__' : null,
        ];
        $eval = $stage->evaluate($env, $supplied, $verifiedFlags);

        $attempt->missing_fields = $eval['missing_fields'];
        $attempt->unverified_fields = $eval['unverified_fields'];

        if (! empty($eval['missing_fields']) || ! empty($eval['unverified_fields'])) {
            $attempt->save();

            return $this->signUpEnvelope($client, $attempt->fresh(), $challenge);
        }

        return DB::transaction(function () use ($env, $client, $attempt, $challenge): JsonResponse {
            $user = User::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'first_name' => $attempt->first_name,
                'last_name' => $attempt->last_name,
                'username' => $attempt->username,
                'password_hash' => $attempt->password_hash,
                'password_changed_at' => $attempt->password_hash !== null ? now() : null,
                'public_metadata' => is_array($attempt->public_metadata) ? $attempt->public_metadata : [],
                'unsafe_metadata' => is_array($attempt->unsafe_metadata) ? $attempt->unsafe_metadata : [],
            ]);

            $email = EmailAddress::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'user_id' => $user->id,
                'email_address' => (string) $attempt->email_address,
                'verified_at' => now(),
                'is_primary' => true,
            ]);

            $user->forceFill(['primary_email_address_id' => $email->id])->saveQuietly();

            $session = Session::create([
                'environment_id' => $env->id,
                'client_id' => $client->id,
                'user_id' => $user->id,
                'status' => Session::STATUS_ACTIVE,
                'was_test' => (bool) $attempt->was_test,
            ]);

            $attempt->forceFill([
                'created_user_id' => $user->id,
                'created_session_id' => $session->id,
                'status' => SignUpAttempt::STATUS_COMPLETE,
                'missing_fields' => [],
                'unverified_fields' => [],
            ])->save();

            Client::query()
                ->withoutGlobalScopes()
                ->where('id', $client->id)
                ->update(['last_active_session_id' => $session->id, 'last_active_at' => now()]);

            $reloadedClient = Client::query()->withoutGlobalScopes()->where('id', $client->id)->first();
            if ($reloadedClient !== null) {
                app(SessionLifecycle::class)
                    ->enforceMultiSessionPolicy($env, $reloadedClient, $session);
            }
            app(SessionLifecycle::class)->notifyCreated($session->fresh() ?? $session);

            app(DomainEnroller::class)->enroll($env, $email->fresh());

            return $this->signUpEnvelope($client, $attempt->fresh(), $challenge, $session);
        });
    }

    private function signUpEnvelope(
        Client $client,
        ?SignUpAttempt $attempt,
        ?Challenge $challenge,
        ?Session $newSession = null,
    ): JsonResponse {
        $response = response()->json([
            'response' => ChallengeResource::from($challenge),
            'client' => ClientResource::from($client->fresh()),
        ], 200)->header('Cache-Control', 'no-store');

        if ($newSession !== null) {
            $response = $response->withCookie($this->buildSessionCookie($newSession));
        }

        return $response;
    }

    private function errorWithSignUp(int $status, string $code, string $message, ?Client $client, ?SignUpAttempt $attempt = null, ?Challenge $challenge = null): JsonResponse
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
        if ($attempt !== null) {
            $body['sign_up'] = SignUpResource::from($attempt->fresh());
        }
        if ($challenge !== null) {
            $body['response'] = ChallengeResource::from($challenge);
        }

        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
