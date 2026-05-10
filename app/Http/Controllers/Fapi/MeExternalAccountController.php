<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Http\Resources\ExternalAccountResource;
use App\Models\EmailAddress;
use App\Models\ExternalAccount;
use App\Models\OauthProvider;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `/v1/me/external-accounts` — end-user social-account inspection +
 * unlink. Creation is exclusive to the OAuth callback path; this
 * controller only reads + deletes.
 *
 * Delete is best-effort revoke (calls `OauthProvider.revocation_endpoint`
 * if configured; ignores non-2xx) before dropping the local row +
 * clearing the linked email's pointer.
 */
final class MeExternalAccountController
{
    public function index(): JsonResponse
    {
        $user = app(User::class);
        $rows = $user->externalAccounts()->withoutGlobalScopes()->get();
        $providers = OauthProvider::query()->withoutGlobalScopes()
            ->whereIn('id', $rows->pluck('oauth_provider_id')->unique()->values())
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $rows->map(fn (ExternalAccount $r) => ExternalAccountResource::from($r, $providers->get($r->oauth_provider_id)))->all(),
            'total_count' => $rows->count(),
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): JsonResponse
    {
        $row = $this->load($request);
        if ($row instanceof JsonResponse) {
            return $row;
        }
        $provider = OauthProvider::query()->withoutGlobalScopes()->where('id', $row->oauth_provider_id)->first();

        return response()->json(ExternalAccountResource::from($row, $provider))
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot unlink external accounts.');
        }
        $row = $this->load($request);
        if ($row instanceof JsonResponse) {
            return $row;
        }

        $provider = OauthProvider::query()->withoutGlobalScopes()->where('id', $row->oauth_provider_id)->first();
        if ($provider !== null) {
            $this->bestEffortRevoke($provider, $row);
        }

        if ($row->email_address !== null) {
            EmailAddress::query()->withoutGlobalScopes()
                ->where('environment_id', $row->environment_id)
                ->where('linked_to_external_account_id', $row->id)
                ->update(['linked_to_external_account_id' => null]);
        }

        $row->delete();

        return response()->json(null, 204);
    }

    private function bestEffortRevoke(OauthProvider $provider, ExternalAccount $row): void
    {
        $endpoint = $this->revocationEndpointFor($provider);
        if ($endpoint === null) {
            return;
        }
        $token = $row->encrypted_refresh_token ?? $row->encrypted_access_token;
        if ($token === null || $token === '') {
            return;
        }
        try {
            Http::asForm()->timeout(5)->post($endpoint, [
                'token' => $token,
                'client_id' => $provider->client_id,
                'client_secret' => $provider->encrypted_client_secret,
            ]);
        } catch (ConnectionException|RequestException|Throwable $e) {
            Log::info('audit:fapi.external_account.revoke_failed', [
                'external_account_id' => $row->id,
                'provider_key' => $provider->provider_key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pull a known revocation endpoint from the provider's stored
     * `additional_authorization_params` when present. v0.4 doesn't
     * model this as a top-level column; operators that want strict
     * revocation set it via the BAPI's `additional_authorization_params`
     * patch surface (key: `revocation_endpoint`).
     */
    private function revocationEndpointFor(OauthProvider $provider): ?string
    {
        $params = is_array($provider->additional_authorization_params) ? $provider->additional_authorization_params : [];
        $value = $params['revocation_endpoint'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function load(Request $request): ExternalAccount|JsonResponse
    {
        $id = (string) $request->route('external_account_id');
        $user = app(User::class);
        $row = ExternalAccount::query()
            ->withoutGlobalScopes()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return $this->error(404, 'external_account_not_found', 'External account not found on this user.');
        }

        return $row;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => $code, 'message' => $message, 'long_message' => $message, 'meta' => []]],
            'trace_id' => null,
        ], $status);
    }
}
