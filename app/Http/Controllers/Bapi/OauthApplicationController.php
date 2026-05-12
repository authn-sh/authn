<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Http\Resources\OauthApplicationResource;
use App\Models\AuthorizationGrant;
use App\Models\Environment;
use App\Models\OauthApplication;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * BAPI `/v1/oauth-applications` CRUD + rotate-secret (OA-2).
 *
 *   GET    /v1/oauth-applications
 *   POST   /v1/oauth-applications                                    (returns plaintext once)
 *   GET    /v1/oauth-applications/{oauth_application_id}
 *   PATCH  /v1/oauth-applications/{oauth_application_id}
 *   DELETE /v1/oauth-applications/{oauth_application_id}             (revokes all grants)
 *   POST   /v1/oauth-applications/{oauth_application_id}/rotate-secret
 *
 * `is_public` + `client_id` are immutable on PATCH. DELETE soft-removes
 * via `removed_at` and revokes every `AuthorizationGrant` row that
 * still points at the application in the same transaction. Public
 * clients (`is_public: true`) have no client_secret to rotate —
 * rotate-secret returns `409 oauth_application_public_client`.
 */
final class OauthApplicationController
{
    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        $query = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->whereNull('removed_at')
            ->orderBy('created_at');

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (OauthApplication $a) => OauthApplicationResource::from($a))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'callback_urls' => ['required', 'array', 'min:1'],
            'callback_urls.*' => ['required', 'string', 'max:2048'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'max:120'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $data = $validator->validated();
        $isPublic = (bool) ($data['is_public'] ?? false);

        // Mint the row first so `client_id` can be derived from its id,
        // then layer in the hashed secret for confidential clients.
        $plaintextSecret = null;
        $hashedSecret = null;
        if (! $isPublic) {
            $minted = OauthApplication::mintClientSecret();
            $plaintextSecret = $minted['plaintext'];
            $hashedSecret = $minted['hash'];
        }

        $row = OauthApplication::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'name' => $data['name'],
            'hashed_client_secret' => $hashedSecret,
            'callback_urls' => array_values($data['callback_urls']),
            'scopes' => array_values($data['scopes'] ?? []),
            'is_public' => $isPublic,
        ]);

        Log::info('audit:bapi.oauth_application.created', [
            'environment_id' => $env->id,
            'oauth_application_id' => $row->id,
        ]);
        app(Emitter::class)->emit('oauthApplication.created', OauthApplicationResource::from($row->fresh()), $env);

        return response()->json(OauthApplicationResource::withSecret($row->fresh(), $plaintextSecret), 201)
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $oauthApplicationId): JsonResponse
    {
        $row = $this->find($oauthApplicationId);
        if ($row === null) {
            return $this->notFound($oauthApplicationId);
        }

        return response()->json(OauthApplicationResource::from($row))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $oauthApplicationId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($oauthApplicationId);
        if ($row === null) {
            return $this->notFound($oauthApplicationId);
        }

        if ($request->has('is_public') && $request->boolean('is_public') !== (bool) $row->is_public) {
            return $this->error(422, 'is_public_immutable',
                'is_public cannot change after an OauthApplication is created — re-create under a new id to migrate.');
        }
        if ($request->has('client_id') && $request->input('client_id') !== $row->client_id) {
            return $this->error(422, 'client_id_immutable',
                'client_id is derived from the row id and cannot be changed.');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'callback_urls' => ['sometimes', 'array', 'min:1'],
            'callback_urls.*' => ['required', 'string', 'max:2048'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'max:120'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $data = $validator->validated();

        $row->fill(array_intersect_key($data, array_flip(['name', 'callback_urls', 'scopes'])));
        $row->save();

        Log::info('audit:bapi.oauth_application.updated', [
            'environment_id' => $env->id,
            'oauth_application_id' => $row->id,
        ]);
        app(Emitter::class)->emit('oauthApplication.updated', OauthApplicationResource::from($row->fresh()), $env);

        return response()->json(OauthApplicationResource::from($row->fresh()))->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $oauthApplicationId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($oauthApplicationId);
        if ($row === null) {
            return $this->notFound($oauthApplicationId);
        }

        $snapshot = OauthApplicationResource::from($row);

        DB::transaction(function () use ($row): void {
            // Revoke every active grant for this app in the same tx so
            // tokens carrying a still-active grant can't survive the
            // application's soft-delete.
            AuthorizationGrant::query()->withoutGlobalScopes()
                ->where('oauth_application_id', $row->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $row->delete();
        });

        Log::info('audit:bapi.oauth_application.deleted', [
            'environment_id' => $env->id,
            'oauth_application_id' => $row->id,
        ]);
        app(Emitter::class)->emit('oauthApplication.deleted', $snapshot, $env);

        return response()->json(null, 204);
    }

    public function rotateSecret(Request $request, string $oauthApplicationId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($oauthApplicationId);
        if ($row === null) {
            return $this->notFound($oauthApplicationId);
        }

        if ($row->is_public) {
            return $this->error(409, 'oauth_application_public_client',
                'Public clients have no client_secret to rotate (PKCE-only).');
        }

        $minted = OauthApplication::mintClientSecret();
        $row->forceFill(['hashed_client_secret' => $minted['hash']])->save();

        Log::info('audit:bapi.oauth_application.secret_rotated', [
            'environment_id' => $env->id,
            'oauth_application_id' => $row->id,
        ]);
        app(Emitter::class)->emit('oauthApplication.secret_rotated', OauthApplicationResource::from($row->fresh()), $env);

        return response()->json(OauthApplicationResource::withSecret($row->fresh(), $minted['plaintext']))
            ->header('Cache-Control', 'no-store');
    }

    private function find(string $oauthApplicationId): ?OauthApplication
    {
        $env = app(Environment::class);

        return OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $oauthApplicationId)
            ->whereNull('removed_at')
            ->first();
    }

    private function notFound(string $id): JsonResponse
    {
        return $this->error(404, 'oauth_application_not_found', "OauthApplication {$id} not found.");
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => $code, 'message' => $message, 'long_message' => $message]],
        ], $status)->header('Cache-Control', 'no-store');
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        $flat = [];
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $flat[] = [
                    'code' => 'form_param_invalid',
                    'message' => $message,
                    'long_message' => $message,
                    'meta' => ['param' => $field],
                ];
            }
        }

        return response()->json(['errors' => $flat], 422)->header('Cache-Control', 'no-store');
    }
}
