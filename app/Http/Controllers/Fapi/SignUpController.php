<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\Captcha\CaptchaPolicy;
use App\Auth\ErrorCodes;
use App\Auth\SignUp\IdentifierNormalizer;
use App\Auth\SignUp\IdentifierRestrictions;
use App\Auth\SignUp\StageRequirements;
use App\Auth\SignUp\TicketRedeemer;
use App\Auth\TestMode\Policy as TestModePolicy;
use App\Http\Resources\ClientResource;
use App\Http\Resources\SignUpResource;
use App\Jobs\Mail\SendVerificationEmail;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Invitation;
use App\Models\Session;
use App\Models\SignUpAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Services\Client\ClientResolver;
use App\Services\Domains\DomainEnroller;
use App\Services\Sessions\SessionLifecycle;
use App\Services\Sessions\SessionTokenIssuer;
use App\Services\Verification\VerificationManager;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * FAPI sign-up state-machine controller.
 *
 * Endpoints (PLAN §3.3 / §9.4):
 *   POST   /v1/client/sign_ups
 *   GET    /v1/client/sign_ups/{id}
 *   PATCH  /v1/client/sign_ups/{id}
 *   POST   /v1/client/sign_ups/{id}/prepare_verification
 *   POST   /v1/client/sign_ups/{id}/attempt_verification
 *
 * v0.1 strategies for verification: email_code (and ticket at create-time).
 * email_link, OAuth transfer, organization-creation, phone are all v0.2+.
 */
final class SignUpController
{
    private const EMAIL_VERIFICATION_TTL = 600;

    public function __construct(
        private readonly StageRequirements $stage,
        private readonly IdentifierRestrictions $restrictions,
        private readonly IdentifierNormalizer $normalizer,
        private readonly TicketRedeemer $ticketRedeemer,
        private readonly VerificationManager $verifications,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $createdClient = false;
        $client = $this->resolveOrCreateClient($request, $env, $createdClient);

        if ($request->boolean('transfer')) {
            return $this->error(422, ErrorCodes::TRANSFER_NOT_SUPPORTED_IN_V0_1, 'transfer flow lands in a later milestone.', $client);
        }

        $emailInput = $request->input('email_address');
        $policy = TestModePolicy::resolve($env, is_string($emailInput) ? $emailInput : null);
        if ($policy === TestModePolicy::STATUS_REJECTED) {
            return $this->error(422, ErrorCodes::TEST_IDENTIFIER_FORBIDDEN, 'Test identifiers are not allowed in this environment.', $client);
        }

        $captchaResult = CaptchaPolicy::evaluate($env, $request->input('captcha_token'), is_string($emailInput) ? $emailInput : null);
        if ($captchaResult === CaptchaPolicy::REASON_MISSING_TOKEN) {
            return $this->error(422, ErrorCodes::CAPTCHA_INVALID, 'A captcha token is required for this request.', $client);
        }

        return DB::transaction(function () use ($request, $env, $client, $createdClient): JsonResponse {
            $existing = $client->current_sign_up_attempt_id !== null
                ? SignUpAttempt::query()->withoutGlobalScopes()->where('id', $client->current_sign_up_attempt_id)->first()
                : null;
            if ($existing !== null && ! in_array($existing->status, [SignUpAttempt::STATUS_COMPLETE, SignUpAttempt::STATUS_ABANDONED], true)) {
                return $this->envelope($client, $existing, 200, null, $createdClient);
            }

            $ticket = $request->input('ticket');
            if (is_string($ticket) && $ticket !== '') {
                return $this->createFromTicket($request, $env, $client, $ticket, $createdClient);
            }

            return $this->createInteractive($request, $env, $client, $createdClient);
        });
    }

    public function show(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        return $this->envelope($client, $attempt);
    }

    public function patch(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $env = app(Environment::class);
        $supplied = $this->collectSupplied($request, $attempt);
        $eval = $this->stage->evaluate($env, $supplied, $this->verifiedFlags($attempt));

        if (! empty($eval['rejected'])) {
            return $this->error(422, ErrorCodes::FORM_PARAM_UNKNOWN, 'One or more fields are not enabled for sign-up: '.implode(', ', $eval['rejected']), $client, $attempt);
        }

        $reasonOrAccept = $this->applyIdentifierGuards($env, $supplied['email_address'] ?? $attempt->email_address);
        if ($reasonOrAccept !== null) {
            return $this->error(422, $reasonOrAccept, 'Email is not allowed for sign-up.', $client, $attempt);
        }

        $this->stageOnto($attempt, $supplied, $eval);
        $attempt->save();

        return $this->envelope($client, $attempt->fresh());
    }

    public function prepareVerification(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $strategy = (string) $request->input('strategy', '');
        if ($strategy === '') {
            return $this->error(422, ErrorCodes::FORM_PARAM_NIL, 'strategy is required.', $client, $attempt);
        }
        if ($strategy === Verification::STRATEGY_EMAIL_LINK) {
            return $this->error(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, 'email_link sign-up lands in v0.2.', $client, $attempt);
        }
        if ($strategy !== Verification::STRATEGY_EMAIL_CODE) {
            return $this->error(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, "strategy {$strategy} is not enabled in v0.1.", $client, $attempt);
        }

        if (! is_string($attempt->email_address) || $attempt->email_address === '') {
            return $this->error(422, ErrorCodes::FORM_PARAM_NIL, 'email_address is required before preparing email verification.', $client, $attempt);
        }

        // For sign-up the email isn't yet on an EmailAddress row — start the
        // Verification on the SignUpAttempt itself so the morph is consistent
        // (the staged email lives on the attempt).
        $verification = $this->verifications->start($attempt, Verification::STRATEGY_EMAIL_CODE, self::EMAIL_VERIFICATION_TTL);
        $code = $this->verifications->mintNumericCode($verification, VerificationCode::PURPOSE_EMAIL_CODE, self::EMAIL_VERIFICATION_TTL);

        SendVerificationEmail::dispatch(
            $attempt->environment_id,
            $attempt->email_address,
            $code,
            VerificationCode::PURPOSE_EMAIL_CODE,
            $verification->id,
        );

        $verifications = is_array($attempt->verifications) ? $attempt->verifications : [];
        $verifications['email_address'] = $verification->id;
        $attempt->verifications = $verifications;
        $attempt->save();

        return $this->envelope($client, $attempt->fresh());
    }

    public function attemptVerification(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $client = app(Client::class);
        $attempt = $this->loadAttempt($sid, $client);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }

        $strategy = (string) $request->input('strategy', '');
        if ($strategy !== Verification::STRATEGY_EMAIL_CODE) {
            return $this->error(422, ErrorCodes::STRATEGY_NOT_SUPPORTED_IN_V0_1, "strategy {$strategy} is not enabled in v0.1.", $client, $attempt);
        }
        $code = $request->input('code');
        if (! is_string($code) || $code === '') {
            return $this->error(422, ErrorCodes::FORM_PARAM_NIL, 'code is required.', $client, $attempt);
        }

        $verifications = is_array($attempt->verifications) ? $attempt->verifications : [];
        $vid = $verifications['email_address'] ?? null;
        if (! is_string($vid)) {
            return $this->error(422, ErrorCodes::NO_VERIFICATION_IN_PROGRESS, 'No email verification has been prepared.', $client, $attempt);
        }
        $verification = Verification::query()->withoutGlobalScopes()->where('id', $vid)->first();
        if ($verification === null) {
            return $this->error(422, ErrorCodes::NO_VERIFICATION_IN_PROGRESS, 'No email verification has been prepared.', $client, $attempt);
        }

        $ok = $this->verifications->attempt($verification, $code);
        if (! $ok) {
            $fresh = $verification->fresh();
            $errCode = $fresh->status === Verification::STATUS_FAILED
                ? ErrorCodes::VERIFICATION_FAILED
                : ($fresh->status === Verification::STATUS_EXPIRED
                    ? ErrorCodes::VERIFICATION_EXPIRED
                    : ErrorCodes::FORM_CODE_INCORRECT);

            return $this->error(422, $errCode, 'Incorrect code.', $client, $attempt);
        }

        $env = app(Environment::class);

        return $this->finalizeIfReady($env, $client, $attempt, ['email_address' => true]);
    }

    /* -------------------- create branches -------------------- */

    private function createInteractive(Request $request, Environment $env, Client $client, bool $createdClient): JsonResponse
    {
        $supplied = $this->collectSupplied($request, null);
        $eval = $this->stage->evaluate($env, $supplied);

        if (! empty($eval['rejected'])) {
            return $this->error(422, ErrorCodes::FORM_PARAM_UNKNOWN, 'One or more fields are not enabled for sign-up: '.implode(', ', $eval['rejected']), $client);
        }

        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        if (($userSettings['require_legal_accepted'] ?? false) === true && ! $request->boolean('legal_accepted')) {
            return $this->error(422, ErrorCodes::LEGAL_ACCEPTED_REQUIRED, 'You must accept the legal terms to sign up.', $client);
        }

        if (isset($supplied['email_address'])) {
            $reason = $this->applyIdentifierGuards($env, $supplied['email_address']);
            if ($reason !== null) {
                return $this->error(422, $reason, 'Email is not allowed for sign-up.', $client);
            }
            $canonical = $this->normalizer->canonicalize($supplied['email_address'], $userSettings);
            if ($this->emailAlreadyTaken($env, $canonical)) {
                return $this->error(422, ErrorCodes::FORM_IDENTIFIER_EXISTS, 'That email is already in use.', $client);
            }
            $supplied['email_address'] = $canonical;
        }

        $isTest = TestModePolicy::isTestAttempt($env, $supplied['email_address'] ?? null);
        $attempt = SignUpAttempt::create([
            'environment_id' => $env->id,
            'client_id' => $client->id,
            'was_test' => $isTest,
        ]);
        $this->stageOnto($attempt, $supplied, $eval);
        $attempt->save();

        $client->forceFill(['current_sign_up_attempt_id' => $attempt->id])->saveQuietly();

        return $this->finalizeIfReady($env, $client, $attempt, [], $createdClient);
    }

    private function createFromTicket(Request $request, Environment $env, Client $client, string $ticket, bool $createdClient): JsonResponse
    {
        $result = $this->ticketRedeemer->redeem($env, $ticket);
        if (! $result['ok']) {
            $code = $result['reason'] === TicketRedeemer::REASON_EXPIRED
                ? ErrorCodes::TICKET_EXPIRED
                : ErrorCodes::TICKET_INVALID;

            return $this->error(422, $code, 'This invitation cannot be redeemed.', $client);
        }

        /** @var Invitation $invitation */
        $invitation = $result['invitation'];
        $email = $result['email'];
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $canonical = $this->normalizer->canonicalize($email, $userSettings);
        if ($this->emailAlreadyTaken($env, $canonical)) {
            return $this->error(422, ErrorCodes::FORM_IDENTIFIER_EXISTS, 'That email is already in use.', $client);
        }

        $supplied = $this->collectSupplied($request, null);
        $supplied['email_address'] = $canonical;

        $eval = $this->stage->evaluate($env, $supplied, ['email_address' => true]);
        if (! empty($eval['rejected'])) {
            return $this->error(422, ErrorCodes::FORM_PARAM_UNKNOWN, 'One or more fields are not enabled for sign-up: '.implode(', ', $eval['rejected']), $client);
        }

        $attempt = SignUpAttempt::create(['environment_id' => $env->id, 'client_id' => $client->id]);
        $this->stageOnto($attempt, $supplied, $eval);
        $verifications = is_array($attempt->verifications) ? $attempt->verifications : [];
        $verifications['email_address'] = '__ticket__';
        $attempt->verifications = $verifications;
        if (! empty($result['metadata'])) {
            $attempt->public_metadata = array_merge(is_array($attempt->public_metadata) ? $attempt->public_metadata : [], $result['metadata']);
        }
        $attempt->save();

        $client->forceFill(['current_sign_up_attempt_id' => $attempt->id])->saveQuietly();

        return $this->finalizeIfReady($env, $client, $attempt, ['email_address' => true], $createdClient, $invitation);
    }

    /* -------------------- finalize -------------------- */

    private function finalizeIfReady(
        Environment $env,
        Client $client,
        SignUpAttempt $attempt,
        array $extraVerified = [],
        bool $attachClientCookie = false,
        ?Invitation $redeemingInvitation = null,
    ): JsonResponse {
        $verifiedFlags = array_merge($this->verifiedFlags($attempt), $extraVerified);
        $supplied = [
            'email_address' => $attempt->email_address,
            'username' => $attempt->username,
            'first_name' => $attempt->first_name,
            'last_name' => $attempt->last_name,
            'password' => $attempt->password_hash !== null ? '__present__' : null,
        ];
        $eval = $this->stage->evaluate($env, $supplied, $verifiedFlags);

        $attempt->missing_fields = $eval['missing_fields'];
        $attempt->unverified_fields = $eval['unverified_fields'];

        if (! empty($eval['missing_fields']) || ! empty($eval['unverified_fields'])) {
            $attempt->save();

            return $this->envelope($client, $attempt->fresh(), 200, null, $attachClientCookie);
        }

        // Promote: create User + EmailAddress + Session.
        return DB::transaction(function () use ($env, $client, $attempt, $attachClientCookie, $redeemingInvitation): JsonResponse {
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

            if ($redeemingInvitation !== null) {
                $redeemingInvitation->forceFill([
                    'status' => Invitation::STATUS_ACCEPTED,
                    'redeemed_by_user_id' => $user->id,
                    'accepted_at' => now(),
                ])->save();
                app(Emitter::class)->emit('invitation.accepted', [
                    'object' => 'invitation',
                    'id' => $redeemingInvitation->id,
                    'email_address' => $redeemingInvitation->email_address,
                    'redeemed_by_user_id' => $user->id,
                ], $env);
            }

            // AU-9: domain enrollment automation. Match the freshly-verified
            // primary email against verified OrganizationDomain rows and
            // create the right invitation / membership-request rows.
            app(DomainEnroller::class)->enroll($env, $email->fresh());

            return $this->envelope($client, $attempt->fresh(), 200, $session, $attachClientCookie);
        });
    }

    /* -------------------- helpers -------------------- */

    /**
     * @return array<string, ?string>
     */
    private function collectSupplied(Request $request, ?SignUpAttempt $attempt): array
    {
        return [
            'email_address' => $request->input('email_address', $attempt?->email_address),
            'username' => $request->input('username', $attempt?->username),
            'first_name' => $request->input('first_name', $attempt?->first_name),
            'last_name' => $request->input('last_name', $attempt?->last_name),
            'password' => $request->input('password', $attempt?->password_hash !== null ? '__present__' : null),
        ];
    }

    private function stageOnto(SignUpAttempt $attempt, array $supplied, array $eval): void
    {
        if (isset($supplied['email_address']) && is_string($supplied['email_address'])) {
            $attempt->email_address = strtolower($supplied['email_address']);
        }
        foreach (['username', 'first_name', 'last_name'] as $f) {
            if (isset($supplied[$f]) && is_string($supplied[$f])) {
                $attempt->{$f} = $supplied[$f];
            }
        }
        $password = $supplied['password'] ?? null;
        if (is_string($password) && $password !== '' && $password !== '__present__') {
            $attempt->password_hash = Hash::make($password);
        }
        $attempt->missing_fields = $eval['missing_fields'];
        $attempt->unverified_fields = $eval['unverified_fields'];
    }

    private function verifiedFlags(SignUpAttempt $attempt): array
    {
        $verifications = is_array($attempt->verifications) ? $attempt->verifications : [];
        $flags = [];
        if (isset($verifications['email_address'])) {
            $vid = $verifications['email_address'];
            if ($vid === '__ticket__') {
                $flags['email_address'] = true;
            } elseif (is_string($vid)) {
                $verification = Verification::query()->withoutGlobalScopes()->where('id', $vid)->first();
                if ($verification !== null && $verification->status === Verification::STATUS_VERIFIED) {
                    $flags['email_address'] = true;
                }
            }
        }

        return $flags;
    }

    private function applyIdentifierGuards(Environment $env, ?string $email): ?string
    {
        if (! is_string($email) || $email === '') {
            return null;
        }

        return $this->restrictions->check($env, $email);
    }

    private function emailAlreadyTaken(Environment $env, string $canonicalEmail): bool
    {
        $userSettings = is_array($env->user_settings) ? $env->user_settings : [];
        $rows = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->get(['id', 'email_address']);
        foreach ($rows as $row) {
            $candidate = $this->normalizer->canonicalize((string) $row->email_address, $userSettings);
            if ($candidate === $canonicalEmail) {
                return true;
            }
        }

        return false;
    }

    private function loadAttempt(string $sid, Client $client): SignUpAttempt|JsonResponse
    {
        $attempt = SignUpAttempt::query()
            ->withoutGlobalScopes()
            ->where('id', $sid)
            ->where('client_id', $client->id)
            ->first();
        if ($attempt === null) {
            return $this->error(404, ErrorCodes::SIGN_UP_NOT_FOUND, 'Sign-up attempt not found on this device.', $client);
        }
        if ($attempt->status === SignUpAttempt::STATUS_ABANDONED) {
            return $this->error(422, ErrorCodes::SIGN_UP_ABANDONED, 'This sign-up attempt has been abandoned.', $client, $attempt);
        }
        if ($attempt->status === SignUpAttempt::STATUS_COMPLETE) {
            return $this->error(422, ErrorCodes::SIGN_UP_ALREADY_COMPLETE, 'This sign-up attempt is already complete.', $client, $attempt);
        }

        return $attempt;
    }

    private function resolveOrCreateClient(Request $request, Environment $env, bool &$created = false): Client
    {
        $cookie = $request->cookie('__client');
        if (is_string($cookie)) {
            $client = app(ClientResolver::class)->fromCookie($cookie, $env);
            if ($client !== null) {
                return $client;
            }
        }

        $created = true;

        return Client::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'last_active_at' => now(),
        ]);
    }

    private const SESSION_COOKIE_TTL_SECONDS = 86400;

    private function envelope(
        Client $client,
        ?SignUpAttempt $attempt,
        int $status = 200,
        ?Session $newSession = null,
        bool $attachClientCookie = false,
    ): JsonResponse {
        $response = response()->json([
            'response' => SignUpResource::from($attempt),
            'client' => ClientResource::from($client->fresh()),
        ], $status)->header('Cache-Control', 'no-store');

        if ($attachClientCookie) {
            $response = $response->withCookie($this->buildClientCookie($client));
        }

        if ($newSession !== null) {
            $response = $response->withCookie($this->buildSessionCookie($newSession));
        }

        return $response;
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

    private function buildClientCookie(Client $client): Cookie
    {
        $value = app(ClientResolver::class)->mintCookieValue($client);

        return \Illuminate\Support\Facades\Cookie::make(
            name: '__client',
            value: $value,
            minutes: 60 * 24 * 365,
            path: '/',
            domain: null,
            secure: request()->secure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    private function error(int $status, string $code, string $message, ?Client $client = null, ?SignUpAttempt $attempt = null): JsonResponse
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
            $body['response'] = SignUpResource::from($attempt->fresh());
        }

        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
