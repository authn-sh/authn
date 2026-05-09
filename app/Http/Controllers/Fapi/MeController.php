<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Http\Resources\ClientResource;
use App\Http\Resources\EmailAddressResource;
use App\Http\Resources\UserResource;
use App\Jobs\Mail\SendPasswordChangedNotification;
use App\Jobs\Mail\SendVerificationEmail;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Session;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Services\Sessions\SessionLifecycle;
use App\Services\Verification\VerificationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/v1/me` — the authenticated end-user's view of themselves. Powers the
 * `<UserProfile />` component (PLAN §3.4 / §9.6).
 *
 * v0.1 surface: profile read/update, email-address CRUD + verification,
 * change password, list sessions, delete self. Profile-image upload, password
 * removal, phone numbers, external accounts, passkeys, TOTP, organisation
 * memberships are deferred to later milestones.
 */
final class MeController
{
    private const EMAIL_VERIFICATION_TTL = 600;

    private const WRITABLE_PROFILE_FIELDS = [
        'first_name', 'last_name', 'username', 'image_url', 'locale',
    ];

    public function __construct(
        private readonly VerificationManager $verifications,
        private readonly SessionLifecycle $lifecycle,
    ) {}

    public function show(): JsonResponse
    {
        $user = app(User::class);

        return response()->json(UserResource::from($user))
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot mutate the user.');
        }

        $user = app(User::class);
        foreach (self::WRITABLE_PROFILE_FIELDS as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                if ($value === null || is_string($value)) {
                    $user->{$field} = $value;
                }
            }
        }
        if ($request->has('public_metadata') && is_array($request->input('public_metadata'))) {
            $user->public_metadata = $request->input('public_metadata');
        }
        if ($request->has('unsafe_metadata') && is_array($request->input('unsafe_metadata'))) {
            $user->unsafe_metadata = $request->input('unsafe_metadata');
        }
        $user->save();

        return $this->clientEnvelope(UserResource::from($user->fresh()));
    }

    public function destroy(): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot delete the user.');
        }

        $env = app(Environment::class);
        $user = app(User::class);
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        if (($userSettings['delete_self_enabled'] ?? true) === false) {
            return $this->error(403, ErrorCodes::DELETE_SELF_DISABLED, 'Account deletion is disabled for this environment.');
        }
        if (($user->delete_self_enabled) === false) {
            return $this->error(403, ErrorCodes::DELETE_SELF_DISABLED, 'Account deletion is disabled for this user.');
        }

        $this->endAllSessions($user);
        $user->delete();

        return response()->json(null, 204);
    }

    public function deleteSelf(Request $request): JsonResponse
    {
        $user = app(User::class);
        $confirmation = $request->input('confirmation');
        if (! is_string($confirmation) || $confirmation !== $user->id) {
            return $this->error(422, ErrorCodes::FORM_PARAM_FORMAT_INVALID, 'confirmation must equal the user id.');
        }

        return $this->destroy();
    }

    /* -------------------- email addresses -------------------- */

    public function listEmails(): JsonResponse
    {
        $user = app(User::class);
        $emails = $user->emailAddresses()->withoutGlobalScopes()->get();

        return response()->json([
            'data' => $emails->map(fn (EmailAddress $e) => EmailAddressResource::from($e))->all(),
            'total_count' => $emails->count(),
        ])->header('Cache-Control', 'no-store');
    }

    public function createEmail(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot add email addresses.');
        }
        $env = app(Environment::class);
        $user = app(User::class);

        $address = $request->input('email_address');
        if (! is_string($address) || $address === '' || ! str_contains($address, '@')) {
            return $this->error(422, ErrorCodes::FORM_PARAM_FORMAT_INVALID, 'email_address must be a valid email.');
        }

        $exists = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('email_address', strtolower($address))
            ->exists();
        if ($exists) {
            return $this->error(422, ErrorCodes::FORM_IDENTIFIER_EXISTS, 'That email is already registered.');
        }

        $email = EmailAddress::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'user_id' => $user->id,
            'email_address' => $address,
            'is_primary' => false,
        ]);

        return $this->clientEnvelope(EmailAddressResource::from($email->fresh()));
    }

    public function showEmail(Request $request): JsonResponse
    {
        $email = $this->loadEmail($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }

        return response()->json(EmailAddressResource::from($email))->header('Cache-Control', 'no-store');
    }

    public function updateEmail(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot mutate emails.');
        }
        $email = $this->loadEmail($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }

        if ($request->has('is_primary') && $request->boolean('is_primary') === true) {
            if (! $email->isVerified()) {
                return $this->error(422, ErrorCodes::EMAIL_NOT_VERIFIED, 'Cannot set an unverified email as primary.');
            }
            // Demote the prior primary, promote this one. The observer keeps
            // the User.primary_email_address_id pointer in sync.
            EmailAddress::query()
                ->withoutGlobalScopes()
                ->where('user_id', $email->user_id)
                ->where('id', '!=', $email->id)
                ->update(['is_primary' => false]);
            $email->forceFill(['is_primary' => true])->save();
            User::query()
                ->withoutGlobalScopes()
                ->where('id', $email->user_id)
                ->update(['primary_email_address_id' => $email->id]);
        }

        return $this->clientEnvelope(EmailAddressResource::from($email->fresh()));
    }

    public function deleteEmail(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot delete emails.');
        }
        $email = $this->loadEmail($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }
        if ($email->is_primary) {
            return $this->error(422, ErrorCodes::PRIMARY_EMAIL_NOT_REMOVABLE, 'Cannot delete the primary email. Set another email as primary first.');
        }
        $email->delete();

        return response()->json(null, 204);
    }

    public function prepareEmailVerification(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot verify emails.');
        }
        $email = $this->loadEmail($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }

        $strategy = (string) $request->input('strategy', '');
        if ($strategy !== Verification::STRATEGY_EMAIL_CODE) {
            return $this->error(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, "strategy {$strategy} is not enabled in v0.1.");
        }

        $verification = $this->verifications->start($email, Verification::STRATEGY_EMAIL_CODE, self::EMAIL_VERIFICATION_TTL);
        $code = $this->verifications->mintNumericCode($verification, VerificationCode::PURPOSE_EMAIL_CODE, self::EMAIL_VERIFICATION_TTL);

        SendVerificationEmail::dispatch(
            $email->environment_id,
            $email->email_address,
            $code,
            VerificationCode::PURPOSE_EMAIL_CODE,
            $verification->id,
            $email->id,
        );

        return $this->clientEnvelope(EmailAddressResource::from($email->fresh()));
    }

    public function attemptEmailVerification(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot verify emails.');
        }
        $email = $this->loadEmail($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }

        $code = $request->input('code');
        if (! is_string($code) || $code === '') {
            return $this->error(422, ErrorCodes::FORM_PARAM_NIL, 'code is required.');
        }

        $verification = Verification::query()
            ->withoutGlobalScopes()
            ->where('verifiable_type', $email->getMorphClass())
            ->where('verifiable_id', $email->id)
            ->where('status', Verification::STATUS_UNVERIFIED)
            ->latest('id')
            ->first();
        if ($verification === null) {
            return $this->error(422, ErrorCodes::NO_VERIFICATION_IN_PROGRESS, 'No verification is in progress for this email.');
        }

        $ok = $this->verifications->attempt($verification, $code);
        if (! $ok) {
            $fresh = $verification->fresh();
            $errCode = $fresh->status === Verification::STATUS_FAILED
                ? ErrorCodes::VERIFICATION_FAILED
                : ($fresh->status === Verification::STATUS_EXPIRED
                    ? ErrorCodes::VERIFICATION_EXPIRED
                    : ErrorCodes::FORM_CODE_INCORRECT);

            return $this->error(422, $errCode, 'Incorrect code.');
        }

        $email->forceFill(['verified_at' => now()])->save();

        return $this->clientEnvelope(EmailAddressResource::from($email->fresh()));
    }

    /* -------------------- sessions -------------------- */

    public function listSessions(): JsonResponse
    {
        $user = app(User::class);
        $sessions = Session::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderByDesc('last_active_at')
            ->get();

        return response()->json([
            'data' => $sessions->map(fn (Session $s) => $this->sessionShape($s))->all(),
            'total_count' => $sessions->count(),
        ])->header('Cache-Control', 'no-store');
    }

    /* -------------------- password -------------------- */

    public function changePassword(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot change the password.');
        }

        $user = app(User::class);
        $current = $request->input('current_password');
        $new = $request->input('new_password');

        if ($user->password_hash !== null) {
            if (! is_string($current) || $current === '' || ! $user->checkPassword($current)) {
                return $this->error(422, ErrorCodes::FORM_PASSWORD_INCORRECT, 'Current password is incorrect.');
            }
        }
        if (! is_string($new) || strlen($new) < 8) {
            return $this->error(422, ErrorCodes::FORM_PASSWORD_VALIDATION_FAILED, 'Password must be at least 8 characters.');
        }

        $user->setPassword($new);
        $user->save();

        SendPasswordChangedNotification::dispatch($user->id);

        if ($request->boolean('sign_out_of_other_sessions')) {
            Session::query()
                ->withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('id', '!=', $session->id)
                ->whereIn('status', Session::LIVE_STATUSES)
                ->each(fn (Session $other) => $this->lifecycle->revoke($other));
        }

        return $this->clientEnvelope(['object' => 'change_password', 'success' => true]);
    }

    /* -------------------- helpers -------------------- */

    private function loadEmail(Request $request): EmailAddress|JsonResponse
    {
        $eid = (string) $request->route('eid');
        $user = app(User::class);
        $email = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('id', $eid)
            ->where('user_id', $user->id)
            ->first();
        if ($email === null) {
            return $this->error(404, ErrorCodes::EMAIL_NOT_FOUND, 'Email not found on this user.');
        }

        return $email;
    }

    private function endAllSessions(User $user): void
    {
        Session::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('status', Session::LIVE_STATUSES)
            ->each(fn (Session $s) => $this->lifecycle->end($s));
    }

    private function clientEnvelope(mixed $body, int $status = 200): JsonResponse
    {
        $client = app()->bound(Client::class) ? app(Client::class) : null;

        return response()->json([
            'response' => $body,
            'client' => ClientResource::from($client?->fresh()),
        ], $status)->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionShape(Session $session): array
    {
        return [
            'object' => 'session',
            'id' => $session->id,
            'status' => $session->status,
            'last_active_at' => $session->last_active_at?->getTimestampMs(),
            'expire_at' => $session->expire_at->getTimestampMs(),
            'abandon_at' => $session->abandon_at?->getTimestampMs(),
            'last_active_organization_id' => $session->last_active_organization_id,
            'actor' => $session->actor,
            'user_id' => $session->user_id,
            'client_id' => $session->client_id,
        ];
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ], $status);
    }
}
