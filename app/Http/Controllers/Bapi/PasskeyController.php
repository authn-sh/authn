<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Http\Resources\PasskeyResource;
use App\Models\Environment;
use App\Models\Passkey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * BAPI admin passkey surface (sdk-php SP-1 wraps this):
 *
 *   GET    /v1/passkeys                     — instance-scoped list, filterable by `user_id`
 *   GET    /v1/passkeys/{id}                — single row
 *   PATCH  /v1/passkeys/{id}                — admin nickname update
 *   DELETE /v1/passkeys/{id}                — soft-delete (`removed_at`)
 *
 * Operator-side audit lookups go through this surface; user-side flows
 * use the parallel `/v1/me/passkeys` FAPI controller.
 */
final class PasskeyController
{
    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = Passkey::query()
            ->whereHas('user', fn ($q) => $q->where('environment_id', $env->id))
            ->orderByDesc('created_at');

        if (is_string($request->input('user_id'))) {
            $query->where('user_id', (string) $request->input('user_id'));
        }

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (Passkey $p): array => PasskeyResource::from($p))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(string $passkeyId): JsonResponse
    {
        $env = app(Environment::class);
        $passkey = Passkey::query()
            ->where('id', $passkeyId)
            ->whereHas('user', fn ($q) => $q->where('environment_id', $env->id))
            ->first();
        if ($passkey === null) {
            return $this->error(404, 'resource_not_found', 'Passkey not found.');
        }

        return response()->json(PasskeyResource::from($passkey))
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $passkeyId): JsonResponse
    {
        $env = app(Environment::class);
        $passkey = Passkey::query()
            ->where('id', $passkeyId)
            ->whereHas('user', fn ($q) => $q->where('environment_id', $env->id))
            ->first();
        if ($passkey === null) {
            return $this->error(404, 'resource_not_found', 'Passkey not found.');
        }

        if (! $request->has('nickname')) {
            return $this->error(422, 'form_param_nil', 'nickname is required.');
        }
        $nickname = $request->input('nickname');
        if (! is_string($nickname) || strlen($nickname) > 100) {
            return $this->error(422, 'form_param_format_invalid', 'nickname must be a string up to 100 characters.');
        }

        $passkey->forceFill(['nickname' => $nickname])->save();

        Log::info('auth.passkey.admin_renamed', [
            'environment_id' => $env->id,
            'passkey_id' => $passkey->id,
            'user_id' => $passkey->user_id,
            'surface' => 'bapi',
        ]);

        return response()->json(PasskeyResource::from($passkey->fresh()))
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(string $passkeyId): JsonResponse
    {
        $env = app(Environment::class);
        $passkey = Passkey::query()
            ->where('id', $passkeyId)
            ->whereHas('user', fn ($q) => $q->where('environment_id', $env->id))
            ->first();
        if ($passkey === null) {
            return $this->error(404, 'resource_not_found', 'Passkey not found.');
        }

        $shape = PasskeyResource::from($passkey);
        $passkey->delete();

        Log::info('auth.passkey.admin_removed', [
            'environment_id' => $env->id,
            'passkey_id' => $passkey->id,
            'user_id' => $passkey->user_id,
            'surface' => 'bapi',
        ]);

        return response()->json($shape)->header('Cache-Control', 'no-store');
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
