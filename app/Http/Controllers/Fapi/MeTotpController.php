<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Auth\Mfa\TotpEnrolmentService;
use App\Http\Resources\ClientResource;
use App\Http\Resources\TotpSecretResource;
use App\Jobs\Mail\SendTotpEnabledNotification;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Session;
use App\Models\TotpSecret;
use App\Models\User;
use App\Settings\MultiFactorSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /v1/me/totp` (start), `POST /v1/me/totp/verify`,
 * `GET /v1/me/totp`, `DELETE /v1/me/totp`.
 *
 * The plaintext `secret` / `otpauth_uri` / `qr_code_data_url` only
 * surface on the `start` response. Subsequent reads return them as
 * `null` per the openapi `TotpSecret` contract.
 */
final class MeTotpController
{
    public function __construct(
        private readonly TotpEnrolmentService $enrolment,
    ) {}

    public function start(): JsonResponse
    {
        $env = app(Environment::class);
        $user = app(User::class);
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot enrol MFA.');
        }

        $settings = MultiFactorSettings::fromUserSettings(is_array($env->user_settings) ? $env->user_settings : []);
        if (! $settings->totpEnabled) {
            return $this->error(422, ErrorCodes::MFA_NOT_ENABLED, 'TOTP enrolment is disabled for this environment.');
        }

        $verified = TotpSecret::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->first();
        if ($verified !== null) {
            return $this->error(409, ErrorCodes::MFA_ALREADY_VERIFIED, 'TOTP is already enrolled. Remove the existing secret first.');
        }

        $secret = $this->enrolment->start($user, $env);
        $primaryEmail = EmailAddress::query()
            ->withoutGlobalScopes()
            ->where('id', (string) $user->primary_email_address_id)
            ->first();
        $otpauthUri = $this->enrolment->otpauthUri($secret, $env, $primaryEmail);
        $qr = $this->enrolment->qrCodeDataUrl($otpauthUri);

        return $this->clientEnvelope(TotpSecretResource::from(
            $secret,
            exposedSecret: $secret->secret,
            otpauthUri: $otpauthUri,
            qrCodeDataUrl: $qr,
        ));
    }

    public function verify(Request $request): JsonResponse
    {
        $user = app(User::class);
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot verify MFA.');
        }

        $secret = TotpSecret::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();
        if ($secret === null) {
            return $this->error(404, ErrorCodes::TOTP_NOT_FOUND, 'No TOTP enrolment in progress.');
        }
        if ($secret->isVerified()) {
            return $this->error(409, ErrorCodes::MFA_ALREADY_VERIFIED, 'TOTP is already verified.');
        }

        $code = (string) $request->input('code', '');
        $wasUnverified = ! $secret->isVerified();
        if (! $this->enrolment->verify($secret, $code)) {
            return $this->error(422, ErrorCodes::FORM_CODE_INCORRECT, 'TOTP code is incorrect or expired.');
        }

        if ($wasUnverified) {
            $user->totp_enabled = true;
            $user->two_factor_enabled = true;
            if ($user->mfa_enabled_at === null) {
                $user->mfa_enabled_at = now();
            }
            $user->save();
            SendTotpEnabledNotification::dispatch($user->id);
        }

        return $this->clientEnvelope(TotpSecretResource::from($secret->fresh()));
    }

    public function show(): JsonResponse
    {
        $user = app(User::class);
        $secret = TotpSecret::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->first();
        if ($secret === null) {
            return $this->error(404, ErrorCodes::TOTP_NOT_FOUND, 'No verified TOTP secret on this user.');
        }

        return $this->clientEnvelope(TotpSecretResource::from($secret));
    }

    public function destroy(): JsonResponse
    {
        $user = app(User::class);
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot remove MFA.');
        }

        $secret = TotpSecret::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->first();
        if ($secret === null) {
            return $this->error(404, ErrorCodes::TOTP_NOT_FOUND, 'No verified TOTP secret on this user.');
        }

        $shape = TotpSecretResource::from($secret);
        $this->enrolment->remove($user);

        $user->totp_enabled = false;
        if (! $user->backup_code_enabled) {
            $user->two_factor_enabled = false;
            $user->mfa_disabled_at = now();
        }
        $user->save();

        return $this->clientEnvelope($shape);
    }

    /* -------------------- helpers -------------------- */

    private function clientEnvelope(mixed $body, int $status = 200): JsonResponse
    {
        $client = app()->bound(Client::class) ? app(Client::class) : null;

        return response()->json([
            'response' => $body,
            'client' => ClientResource::from($client?->fresh()),
        ], $status)->header('Cache-Control', 'no-store');
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
