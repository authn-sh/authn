<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Http\Resources\ExternalAccountResource;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\OauthProvider;
use App\Webhooks\Emitter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BAPI admin external-accounts (OA-9 / authn#150).
 *
 *   GET    /v1/external-accounts             — list (filter user_id, oauth_provider_id)
 *   GET    /v1/external-accounts/{id}
 *   DELETE /v1/external-accounts/{id}        — best-effort IdP revocation + drop row
 */
final class ExternalAccountController
{
    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = ExternalAccount::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('linked_at', 'desc');
        if (is_string($request->input('user_id'))) {
            $query->where('user_id', (string) $request->input('user_id'));
        }
        if (is_string($request->input('oauth_provider_id'))) {
            $query->where('oauth_provider_id', (string) $request->input('oauth_provider_id'));
        }

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        $providers = OauthProvider::query()->withoutGlobalScopes()
            ->whereIn('id', $rows->pluck('oauth_provider_id')->unique()->values())
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $rows->map(fn (ExternalAccount $r) => ExternalAccountResource::from($r, $providers->get($r->oauth_provider_id)))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(string $externalAccountId): JsonResponse
    {
        $row = $this->find($externalAccountId);
        if ($row === null) {
            return $this->error(404, 'external_account_not_found', 'No external account matches that id in this environment.');
        }
        $provider = OauthProvider::query()->withoutGlobalScopes()->where('id', $row->oauth_provider_id)->first();

        return response()->json(ExternalAccountResource::from($row, $provider))
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(string $externalAccountId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($externalAccountId);
        if ($row === null) {
            return $this->error(404, 'external_account_not_found', 'No external account matches that id in this environment.');
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

        $snapshot = ExternalAccountResource::from($row, $provider);
        $row->delete();

        Log::info('audit:bapi.external_account.unlinked', [
            'environment_id' => $env->id,
            'external_account_id' => $externalAccountId,
            'provider_key' => $provider?->provider_key,
        ]);

        app(Emitter::class)->emit('externalAccount.unlinked', $snapshot, $env);

        return response()->json(null, 204);
    }

    private function bestEffortRevoke(OauthProvider $provider, ExternalAccount $row): void
    {
        $params = is_array($provider->additional_authorization_params) ? $provider->additional_authorization_params : [];
        $endpoint = is_string($params['revocation_endpoint'] ?? null) ? (string) $params['revocation_endpoint'] : null;
        if ($endpoint === null || $endpoint === '') {
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
            Log::info('audit:bapi.external_account.revoke_failed', [
                'external_account_id' => $row->id,
                'provider_key' => $provider->provider_key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function find(string $externalAccountId): ?ExternalAccount
    {
        $env = app(Environment::class);

        return ExternalAccount::query()->withoutGlobalScopes()
            ->where('id', $externalAccountId)
            ->where('environment_id', $env->id)
            ->first();
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => $code, 'long_message' => $message, 'message' => $message]],
        ], $status);
    }
}
