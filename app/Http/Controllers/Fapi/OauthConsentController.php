<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Models\AuthorizationGrant;
use App\Models\Environment;
use App\Models\OauthApplication;
use App\Models\Session;
use App\Services\Client\ClientResolver;
use App\Support\Base64Url;
use App\Support\Url;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0.7 Account Portal — OAuth consent screen at /oauth/consent/{request_id}.
 *
 *   GET  /oauth/consent/{request_id}            Inertia consent screen
 *   POST /oauth/consent/{request_id}/accept     create grant + auth code → redirect
 *   POST /oauth/consent/{request_id}/deny       error=access_denied redirect
 *
 * The parked oauth_request_contexts row was minted by AU-6's
 * /oauth/authorize when the calling user lacked a covering
 * AuthorizationGrant. 10-minute TTL; expired contexts return 404.
 */
final class OauthConsentController
{
    public const AUTHORIZATION_CODE_TTL_SECONDS = 5 * 60;

    public function __construct(private readonly ClientResolver $clientResolver) {}

    public function show(Request $request): InertiaResponse|Response|RedirectResponse
    {
        $context = $this->loadContext($request);
        if ($context === null) {
            return response('consent request expired or invalid', 404)
                ->header('Cache-Control', 'no-store');
        }
        if ($this->signedInSession($request) === null) {
            return redirect(Url::accountPortal($context->_env, '/sign-in')
                .'?continue_url='.urlencode($request->fullUrl()));
        }
        $params = is_array(json_decode((string) $context->params, true)) ? json_decode((string) $context->params, true) : [];
        $app = OauthApplication::query()->withoutGlobalScopes()
            ->where('id', $context->oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($app === null) {
            return response('oauth application no longer exists', 404)
                ->header('Cache-Control', 'no-store');
        }

        $scopes = $this->scopeListFromParams($params);

        return Inertia::render('AccountPortal/Oauth/ConsentScreen', [
            'request_id' => $context->request_id,
            'application' => [
                'id' => $app->id,
                'name' => $app->name,
            ],
            'scopes' => array_map(fn (string $s) => [
                'name' => $s,
                'label' => $this->humanizeScope($s),
            ], $scopes),
        ]);
    }

    public function accept(Request $request): JsonResponse|RedirectResponse|Response
    {
        $context = $this->loadContext($request);
        if ($context === null) {
            return response('consent request expired or invalid', 404)
                ->header('Cache-Control', 'no-store');
        }

        $session = $this->signedInSession($request);
        if ($session === null) {
            return response()->json([
                'errors' => [['code' => 'no_active_session', 'message' => 'You must be signed in to consent.']],
            ], 401);
        }

        $params = (array) (json_decode((string) $context->params, true) ?: []);
        $scopes = $this->scopeListFromParams($params);
        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        $state = (string) ($params['state'] ?? '');
        $codeChallenge = (string) ($params['code_challenge'] ?? '');
        $codeChallengeMethod = (string) ($params['code_challenge_method'] ?? '');
        $nonce = (string) ($params['nonce'] ?? '');

        $env = $context->_env;
        $app = OauthApplication::query()->withoutGlobalScopes()
            ->where('id', $context->oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($app === null) {
            return response()->json([
                'errors' => [['code' => 'oauth_application_not_found', 'message' => 'The application no longer exists.']],
            ], 404);
        }

        $code = 'oacd_'.Base64Url::encode(random_bytes(32));

        DB::transaction(function () use ($env, $app, $session, $scopes, $redirectUri, $codeChallenge, $codeChallengeMethod, $nonce, $state, $code, $context): void {
            // Persist the grant (deduped against any existing active row for
            // the same (user, app, scopes) tuple) — partial unique index on
            // Postgres handles the race; SQLite just no-ops the second insert.
            AuthorizationGrant::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'oauth_application_id' => $app->id,
                'user_id' => $session->user_id,
                'scopes' => $scopes,
                'scopes_hash' => AuthorizationGrant::hashScopes($scopes),
                'granted_at' => now(),
            ]);

            DB::table('oauth_authorization_codes')->insert([
                'code' => $code,
                'environment_id' => $env->id,
                'oauth_application_id' => $app->id,
                'user_id' => $session->user_id,
                'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
                'redirect_uri' => $redirectUri,
                'code_challenge' => $codeChallenge !== '' ? $codeChallenge : null,
                'code_challenge_method' => $codeChallengeMethod !== '' ? $codeChallengeMethod : null,
                'nonce' => $nonce !== '' ? $nonce : null,
                'state' => $state,
                'expires_at' => now()->addSeconds(self::AUTHORIZATION_CODE_TTL_SECONDS),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Burn the request context so the same code can't ride twice
            // through the consent screen.
            DB::table('oauth_request_contexts')->where('request_id', $context->request_id)->delete();
        });

        Log::info('audit:fapi.oauth.consent_accepted', [
            'environment_id' => $env->id,
            'user_id' => $session->user_id,
            'oauth_application_id' => $app->id,
        ]);
        app(Emitter::class)->emit('authorizationGrant.granted', [
            'oauth_application_id' => $app->id,
            'user_id' => $session->user_id,
            'scopes' => $scopes,
        ], $env);

        $target = $this->appendQuery($redirectUri, [
            'code' => $code,
            'state' => $state,
        ]);

        return $this->envelope($request, $target);
    }

    public function deny(Request $request): JsonResponse|RedirectResponse|Response
    {
        $context = $this->loadContext($request);
        if ($context === null) {
            return response('consent request expired or invalid', 404)
                ->header('Cache-Control', 'no-store');
        }
        $params = (array) (json_decode((string) $context->params, true) ?: []);
        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        $state = (string) ($params['state'] ?? '');

        DB::table('oauth_request_contexts')->where('request_id', $context->request_id)->delete();

        Log::info('audit:fapi.oauth.consent_denied', [
            'environment_id' => $context->_env->id,
            'oauth_application_id' => $context->oauth_application_id,
        ]);

        $target = $this->appendQuery($redirectUri, [
            'error' => 'access_denied',
            'error_description' => 'The user denied the consent request.',
            'state' => $state,
        ]);

        return $this->envelope($request, $target);
    }

    /**
     * Pulls the request context row out of the DB, env-pinned and TTL-checked.
     * Decorates with the resolved Environment so callers don't have to refetch.
     */
    private function loadContext(Request $request): ?object
    {
        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        if (! $env instanceof Environment) {
            return null;
        }
        $requestId = (string) $request->route('request_id');
        $row = DB::table('oauth_request_contexts')
            ->where('request_id', $requestId)
            ->where('environment_id', $env->id)
            ->where('expires_at', '>', now())
            ->first();
        if ($row === null) {
            return null;
        }
        $row->_env = $env;

        return $row;
    }

    private function signedInSession(Request $request): ?Session
    {
        $env = app(Environment::class);
        $client = $this->clientResolver->fromCookie(
            (string) ($request->cookie('__client') ?? ''),
            $env,
        );
        if ($client === null) {
            return null;
        }

        return Session::query()
            ->where('client_id', $client->id)
            ->whereIn('status', Session::LIVE_STATUSES)
            ->orderByDesc('last_active_at')
            ->first();
    }

    /**
     * @return list<string>
     */
    private function scopeListFromParams(array $params): array
    {
        $raw = (string) ($params['scope'] ?? '');

        return array_values(array_filter(
            preg_split('/\s+/', $raw) ?: [],
            static fn ($s) => is_string($s) && $s !== '',
        ));
    }

    private function humanizeScope(string $scope): string
    {
        return match ($scope) {
            'openid' => 'Verify your identity',
            'profile' => 'View your profile',
            'email' => 'View your email address',
            default => $scope,
        };
    }

    /**
     * @param  array<string, string>  $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url.$sep.http_build_query($params);
    }

    private function envelope(Request $request, string $redirectUrl): JsonResponse|RedirectResponse
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        if (str_contains($accept, 'application/json') && ! str_contains($accept, 'text/html')) {
            return response()->json([
                'object' => 'oauth_consent_result',
                'redirect_url' => $redirectUrl,
            ])->header('Cache-Control', 'no-store');
        }

        return redirect()->away($redirectUrl, 302)
            ->withHeaders(['Cache-Control' => 'no-store']);
    }
}
