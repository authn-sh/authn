<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Models\AuthorizationGrant;
use App\Models\Client;
use App\Models\Environment;
use App\Models\OauthApplication;
use App\Models\Session;
use App\Services\Client\ClientResolver;
use App\Support\Base64Url;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * v0.7 OAuth provider mode entry point — RFC 6749 §4.1 + OIDC §3.1.2.
 *
 *   GET /oauth/authorize?client_id=&redirect_uri=&response_type=code
 *                       &scope=&state=&nonce=&code_challenge=
 *                       &code_challenge_method=S256&prompt=
 *
 * Outcome routing (all 302s ride Cache-Control: no-store):
 *
 *   - No active session  →  /sign-in?continue_url=<this URL>
 *   - First-time consent →  /oauth/consent/{request_id}     (AU-9)
 *   - Silent reuse       →  <redirect_uri>?code=…&state=…
 *
 * `client_id` and `redirect_uri` errors return JSON 400 — redirecting to
 * an unvetted URL with `error=` would leak the validation outcome to a
 * caller-controlled domain. Every later validation error (scope, PKCE,
 * etc.) rides the redirect back to `redirect_uri` per RFC 6749 §4.1.2.1.
 */
final class OauthAuthorizeController
{
    public const REQUEST_CONTEXT_TTL_SECONDS = 10 * 60;

    public const AUTHORIZATION_CODE_TTL_SECONDS = 5 * 60;

    public function __construct(private readonly ClientResolver $clientResolver) {}

    public function __invoke(Request $request): JsonResponse|RedirectResponse|Response
    {
        $env = app(Environment::class);

        // --- Step 1+2: validate client_id + redirect_uri (JSON 400 on miss).

        $clientIdParam = (string) $request->query('client_id', '');
        $redirectUri = (string) $request->query('redirect_uri', '');
        if ($clientIdParam === '' || $redirectUri === '') {
            return $this->jsonError(400, 'oauth_invalid_request',
                'client_id and redirect_uri are required.');
        }

        $app = OauthApplication::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('client_id', $clientIdParam)
            ->whereNull('removed_at')
            ->first();
        if ($app === null) {
            return $this->jsonError(400, 'oauth_invalid_client',
                'Unknown client_id.');
        }
        $callbackUrls = is_array($app->callback_urls) ? $app->callback_urls : [];
        if (! in_array($redirectUri, $callbackUrls, true)) {
            return $this->jsonError(400, 'oauth_invalid_redirect_uri',
                'redirect_uri is not on the application\'s callback_urls allowlist.');
        }

        // --- Step 3+: validation errors below ride the redirect with
        // `error=…&state=…` (RFC 6749 §4.1.2.1).

        $state = (string) $request->query('state', '');
        if ($state === '') {
            return $this->errorRedirect($redirectUri, 'invalid_request', 'state is required', '');
        }

        $responseType = (string) $request->query('response_type', '');
        if ($responseType !== 'code') {
            return $this->errorRedirect($redirectUri, 'unsupported_response_type',
                'Only response_type=code is supported.', $state);
        }

        $rawScope = trim((string) $request->query('scope', ''));
        if ($rawScope === '') {
            return $this->errorRedirect($redirectUri, 'invalid_scope', 'scope is required.', $state);
        }
        $requestedScopes = array_values(array_filter(
            preg_split('/\s+/', $rawScope) ?: [],
            static fn ($s) => is_string($s) && $s !== '',
        ));
        $appScopes = is_array($app->scopes) ? $app->scopes : [];
        $extras = array_values(array_diff($requestedScopes, $appScopes));
        if (! empty($extras)) {
            return $this->errorRedirect($redirectUri, 'invalid_scope',
                'Requested scopes are not registered on the application: '.implode(' ', $extras),
                $state);
        }

        // OIDC: nonce required when `openid` is in the scope set.
        $nonce = (string) $request->query('nonce', '');
        if (in_array('openid', $requestedScopes, true) && $nonce === '') {
            return $this->errorRedirect($redirectUri, 'invalid_request',
                'nonce is required when the openid scope is requested.', $state);
        }

        // PKCE: required for public clients; method must be S256.
        $codeChallenge = (string) $request->query('code_challenge', '');
        $codeChallengeMethod = (string) $request->query('code_challenge_method', '');
        if ($app->is_public) {
            if ($codeChallenge === '' || $codeChallengeMethod === '') {
                return $this->errorRedirect($redirectUri, 'invalid_request',
                    'code_challenge + code_challenge_method=S256 are required for public clients.',
                    $state);
            }
        }
        if ($codeChallenge !== '' && $codeChallengeMethod !== 'S256') {
            return $this->errorRedirect($redirectUri, 'invalid_request',
                'Only code_challenge_method=S256 is supported.', $state);
        }

        $prompt = (string) $request->query('prompt', '');

        // --- Resolve the calling user from the __client cookie + live session.
        $client = $this->clientResolver->fromCookie(
            (string) ($request->cookie('__client') ?? ''),
            $env,
        );
        $session = $client !== null ? $this->liveSessionFor($client) : null;

        if ($session === null) {
            if ($prompt === 'none') {
                return $this->errorRedirect($redirectUri, 'login_required',
                    'No active session and prompt=none was supplied.', $state);
            }

            $signInUrl = Url::accountPortal($env, '/sign-in').'?continue_url='.urlencode($request->fullUrl());

            return $this->result($request, $signInUrl);
        }

        // --- Silent reuse?
        $scopesHash = AuthorizationGrant::hashScopes($requestedScopes);
        $grant = AuthorizationGrant::query()->withoutGlobalScopes()
            ->where('user_id', $session->user_id)
            ->where('oauth_application_id', $app->id)
            ->where('scopes_hash', $scopesHash)
            ->whereNull('revoked_at')
            ->first();

        if ($grant !== null && $prompt !== 'consent' && $prompt !== 'select_account') {
            $code = $this->mintAuthorizationCode(
                env: $env,
                app: $app,
                userId: $session->user_id,
                scopes: $requestedScopes,
                redirectUri: $redirectUri,
                codeChallenge: $codeChallenge !== '' ? $codeChallenge : null,
                codeChallengeMethod: $codeChallengeMethod !== '' ? $codeChallengeMethod : null,
                nonce: $nonce !== '' ? $nonce : null,
                state: $state,
            );
            $target = $this->appendQuery($redirectUri, [
                'code' => $code,
                'state' => $state,
            ]);

            return $this->result($request, $target);
        }

        // --- Park the request for the consent page (AU-9).
        $requestId = $this->parkRequestContext($env, $app, [
            'client_id' => $app->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $requestedScopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'prompt' => $prompt,
        ]);

        $consentUrl = Url::accountPortal($env, '/oauth/consent/'.$requestId);

        return $this->result($request, $consentUrl);
    }

    private function liveSessionFor(Client $client): ?Session
    {
        return Session::query()
            ->where('client_id', $client->id)
            ->whereIn('status', Session::LIVE_STATUSES)
            ->orderByDesc('last_active_at')
            ->first();
    }

    /**
     * @param  list<string>  $scopes
     */
    private function mintAuthorizationCode(
        Environment $env,
        OauthApplication $app,
        string $userId,
        array $scopes,
        string $redirectUri,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        ?string $nonce,
        string $state,
    ): string {
        $code = 'oacd_'.Base64Url::encode(random_bytes(32));
        DB::table('oauth_authorization_codes')->insert([
            'code' => $code,
            'environment_id' => $env->id,
            'oauth_application_id' => $app->id,
            'user_id' => $userId,
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'nonce' => $nonce,
            'state' => $state,
            'expires_at' => now()->addSeconds(self::AUTHORIZATION_CODE_TTL_SECONDS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Opportunistic GC keeps the table small.
        DB::table('oauth_authorization_codes')->where('expires_at', '<', now()->subMinutes(10))->delete();

        return $code;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function parkRequestContext(Environment $env, OauthApplication $app, array $params): string
    {
        $requestId = 'oareq_'.Base64Url::encode(random_bytes(24));
        DB::table('oauth_request_contexts')->insert([
            'request_id' => $requestId,
            'environment_id' => $env->id,
            'oauth_application_id' => $app->id,
            'params' => json_encode($params, JSON_THROW_ON_ERROR),
            'expires_at' => now()->addSeconds(self::REQUEST_CONTEXT_TTL_SECONDS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('oauth_request_contexts')->where('expires_at', '<', now()->subMinutes(10))->delete();

        return $requestId;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        $qs = http_build_query($params);

        return $url.$sep.$qs;
    }

    private function errorRedirect(string $redirectUri, string $error, string $description, string $state): RedirectResponse
    {
        $params = ['error' => $error, 'error_description' => $description];
        if ($state !== '') {
            $params['state'] = $state;
        }

        return redirect()->away($this->appendQuery($redirectUri, $params), 302)
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    private function jsonError(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => $code, 'message' => $message, 'long_message' => $message]],
        ], $status)->header('Cache-Control', 'no-store');
    }

    /**
     * Either 200 JSON (`Accept: application/json`) or 302 (browser GET).
     */
    private function result(Request $request, string $redirectUrl): JsonResponse|RedirectResponse
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        if (str_contains($accept, 'application/json') && ! str_contains($accept, 'text/html')) {
            return response()->json([
                'object' => 'oauth_authorize_result',
                'redirect_url' => $redirectUrl,
            ])->header('Cache-Control', 'no-store');
        }

        return redirect()->away($redirectUrl, 302)
            ->withHeaders(['Cache-Control' => 'no-store']);
    }
}
