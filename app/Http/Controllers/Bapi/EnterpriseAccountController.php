<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Http\Resources\EnterpriseAccountResource;
use App\Models\EnterpriseAccount;
use App\Models\Environment;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * BAPI admin enterprise-accounts. Mirrors the v0.4 ExternalAccountController
 * shape for SDK consistency. Read + unlink only — provisioning happens
 * automatically through `EnterpriseSsoCallbackController` (AU-7) on the
 * SSO callback, not via BAPI.
 *
 *   GET    /v1/enterprise-accounts             — list (filter user_id, enterprise_connection_id)
 *   GET    /v1/enterprise-accounts/{id}
 *   DELETE /v1/enterprise-accounts/{id}        — soft-delete + emit unlink event
 */
final class EnterpriseAccountController
{
    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = EnterpriseAccount::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('linked_at', 'desc');

        if (is_string($request->input('user_id'))) {
            $query->where('user_id', (string) $request->input('user_id'));
        }
        if (is_string($request->input('enterprise_connection_id'))) {
            $query->where('enterprise_connection_id', (string) $request->input('enterprise_connection_id'));
        }

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (EnterpriseAccount $r) => EnterpriseAccountResource::from($r))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): JsonResponse
    {
        $row = $this->find((string) $request->route('enterprise_account_id'));
        if ($row === null) {
            return $this->error(404, 'enterprise_account_not_found', 'No enterprise account matches that id in this environment.');
        }

        return response()->json(EnterpriseAccountResource::from($row))->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find((string) $request->route('enterprise_account_id'));
        if ($row === null) {
            return $this->error(404, 'enterprise_account_not_found', 'No enterprise account matches that id in this environment.');
        }

        $snapshot = EnterpriseAccountResource::from($row);
        $row->delete();

        Log::info('audit:bapi.enterprise_account.unlinked', [
            'environment_id' => $env->id,
            'enterprise_account_id' => $row->id,
            'enterprise_connection_id' => $row->enterprise_connection_id,
        ]);
        app(Emitter::class)->emit('enterpriseAccount.unlinked', $snapshot, $env);

        return response()->json(null, 204);
    }

    private function find(string $enterpriseAccountId): ?EnterpriseAccount
    {
        $env = app(Environment::class);

        return EnterpriseAccount::query()->withoutGlobalScopes()
            ->where('id', $enterpriseAccountId)
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
