<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Http\Resources\AuthorizationGrantResource;
use App\Models\AuthorizationGrant;
use App\Models\Environment;
use App\Models\OauthApplication;
use App\Models\User;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * FAPI `/v1/me/authorized-apps` — the authenticated end-user's view of
 * the OAuth applications they have an active `AuthorizationGrant` to.
 * Drives the `<UserProfile />` Authorized Apps panel (AU-10) and the
 * sdk-react `<UserProfileAuthorizedAppsPanel />` (JS-2).
 *
 *   GET    /v1/me/authorized-apps
 *   DELETE /v1/me/authorized-apps/{authorization_grant_id}
 *
 * Revoking a grant stamps `revoked_at` AND tombstones every still-live
 * authorization code minted under it — long-running access / refresh
 * token issuance lands in AU-7 / AU-8 and will reuse the same revoke
 * hook.
 */
final class MeAuthorizedAppsController
{
    public function index(Request $request): JsonResponse
    {
        $user = app(User::class);

        $rows = AuthorizationGrant::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->with('oauthApplication')
            ->orderByDesc('granted_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (AuthorizationGrant $g) => AuthorizationGrantResource::from($g))->all(),
            'total_count' => $rows->count(),
        ])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $user = app(User::class);
        $grantId = (string) $request->route('authorization_grant_id');

        $grant = AuthorizationGrant::query()->withoutGlobalScopes()
            ->where('id', $grantId)
            ->where('user_id', $user->id)
            ->first();
        if ($grant === null) {
            return $this->error(404, 'authorization_grant_not_found',
                "AuthorizationGrant {$grantId} is not granted to this user.");
        }
        if (! $grant->isActive()) {
            // Idempotent: already revoked, return current shape.
            return response()->json(AuthorizationGrantResource::from($grant->fresh()))
                ->header('Cache-Control', 'no-store');
        }

        $app = OauthApplication::query()->withoutGlobalScopes()
            ->where('id', $grant->oauth_application_id)
            ->first();

        $grant->revoke();
        // AU-7's /oauth/token (which owns the oauth_authorization_codes
        // table) cleans up still-live codes via this same revoked_at
        // signal. Stamping it here is the source of truth.

        Log::info('audit:fapi.authorization_grant.revoked', [
            'environment_id' => $env->id,
            'user_id' => $user->id,
            'oauth_application_id' => $grant->oauth_application_id,
            'authorization_grant_id' => $grant->id,
        ]);
        app(Emitter::class)->emit(
            'authorizationGrant.revoked',
            AuthorizationGrantResource::from($grant->fresh(), $app),
            $env,
        );

        return response()->json(AuthorizationGrantResource::from($grant->fresh(), $app))
            ->header('Cache-Control', 'no-store');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => $code, 'message' => $message, 'long_message' => $message]],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
