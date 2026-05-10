<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Auth\Mfa\BackupCodesService;
use App\Http\Resources\BackupCodeBatchResource;
use App\Http\Resources\ClientResource;
use App\Jobs\Mail\SendBackupCodesGeneratedNotification;
use App\Jobs\Mail\SendMfaDisabledNotification;
use App\Models\BackupCode;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Session;
use App\Models\TotpSecret;
use App\Models\User;
use App\Settings\MultiFactorSettings;
use Illuminate\Http\JsonResponse;

/**
 * `POST /v1/me/backup-codes` regenerates a one-time `BackupCodeBatch`,
 * `GET /v1/me/backup-codes` returns the current unspent count (no
 * plaintext re-surfaced), `DELETE /v1/me/backup-codes` removes every
 * unspent row.
 *
 * Backup codes are gated on a verified TOTP enrolment — they're a
 * recovery factor, not a standalone primary one.
 */
final class MeBackupCodesController
{
    public function __construct(
        private readonly BackupCodesService $service,
    ) {}

    public function regenerate(): JsonResponse
    {
        $env = app(Environment::class);
        $user = app(User::class);
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot regenerate backup codes.');
        }

        $settings = MultiFactorSettings::fromUserSettings(is_array($env->user_settings) ? $env->user_settings : []);
        if (! $settings->backupCodesEnabled) {
            return $this->error(422, ErrorCodes::MFA_NOT_ENABLED, 'Backup codes are disabled for this environment.');
        }

        $hasVerifiedTotp = TotpSecret::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->exists();
        if (! $hasVerifiedTotp) {
            return $this->error(422, ErrorCodes::MFA_PRIMARY_FACTOR_REQUIRED, 'Set up an authenticator app before generating backup codes.');
        }

        $codes = $this->service->regenerate($user, $env, $settings->backupCodesDefaultCount);

        $user->backup_code_enabled = true;
        if (! $user->two_factor_enabled) {
            $user->two_factor_enabled = true;
            if ($user->mfa_enabled_at === null) {
                $user->mfa_enabled_at = now();
            }
        }
        $user->save();

        SendBackupCodesGeneratedNotification::dispatch($user->id, count($codes));

        return $this->clientEnvelope(BackupCodeBatchResource::from($user->fresh(), $codes));
    }

    public function show(): JsonResponse
    {
        $user = app(User::class);
        $count = $this->service->unspentCount($user);

        return $this->clientEnvelope([
            'object' => 'backup_code_batch',
            'user_id' => $user->id,
            'count' => $count,
            'codes' => [],
            'generated_at' => $this->latestGeneratedAtMs($user) ?? now()->getTimestampMs(),
        ]);
    }

    public function destroy(): JsonResponse
    {
        $user = app(User::class);
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot remove backup codes.');
        }

        $this->service->removeUnspent($user);

        $user->backup_code_enabled = false;
        $hasTotp = TotpSecret::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->exists();
        $disabledMfa = false;
        if (! $hasTotp) {
            $user->two_factor_enabled = false;
            $user->mfa_disabled_at = now();
            $disabledMfa = true;
        }
        $user->save();

        if ($disabledMfa) {
            SendMfaDisabledNotification::dispatch($user->id);
        }

        return $this->clientEnvelope(BackupCodeBatchResource::empty($user->fresh()));
    }

    /* -------------------- helpers -------------------- */

    private function latestGeneratedAtMs(User $user): ?int
    {
        $row = BackupCode::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        return $row?->created_at?->getTimestampMs();
    }

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
