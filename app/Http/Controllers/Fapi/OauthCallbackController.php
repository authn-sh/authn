<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\Oauth\Exceptions\OauthDiscoveryFailedException;
use App\Auth\Oauth\OauthProviderResolver;
use App\Auth\Oauth\ResolvedProvider;
use App\Auth\Oauth\StateToken;
use App\Http\Resources\ExternalAccountResource;
use App\Models\Challenge;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\OauthProvider;
use App\Models\Session;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use App\Webhooks\Emitter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `GET /v1/oauth-callback/{provider_key}` — landing endpoint the IdP
 * redirects to after the user has consented. Verifies `state`, exchanges
 * the authorization code for tokens, fetches userinfo, applies
 * `attribute_mapping`, finds-or-creates the User + ExternalAccount, and
 * 302-redirects to `redirect_url_complete`.
 *
 * Strict best-effort posture: any unrecoverable failure flips the parent
 * Verification to `failed` and 302's to `redirect_url?__authn_error=…`
 * so the SDK can surface the error inline.
 */
final class OauthCallbackController
{
    public function __construct(
        private readonly OauthProviderResolver $resolver,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $provider_key = (string) $request->route('provider_key');
        $stateToken = (string) $request->query('state', '');
        $state = StateToken::unwrap($stateToken);
        if ($state === null) {
            return $this->fail(null, null, 'state_invalid', 'state token is missing or expired.');
        }
        if (($state['pk'] ?? null) !== $provider_key) {
            return $this->fail($state['ru'] ?? null, null, 'state_provider_mismatch', 'state.provider_key does not match.');
        }

        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        if ($env === null || $env->id !== ($state['env'] ?? null)) {
            return $this->fail($state['ru'] ?? null, null, 'state_env_mismatch', 'state.environment_id does not match.');
        }

        $verification = Verification::query()->withoutGlobalScopes()
            ->where('id', (string) ($state['v'] ?? ''))
            ->where('environment_id', $env->id)
            ->first();
        if ($verification === null) {
            return $this->fail($state['ru'] ?? null, null, 'verification_not_found', 'verification row missing.');
        }

        $provider = OauthProvider::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('provider_key', $provider_key)
            ->first();
        if ($provider === null) {
            return $this->fail($state['ru'] ?? null, $verification, 'oauth_provider_not_found', 'OauthProvider row missing.');
        }

        if ($request->query('error') !== null) {
            $code = (string) $request->query('error');
            $description = (string) $request->query('error_description', '');

            return $this->fail($state['ru'] ?? null, $verification, $code, $description !== '' ? $description : 'IdP returned an error.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $this->fail($state['ru'] ?? null, $verification, 'oauth_code_missing', 'IdP did not return a code.');
        }

        try {
            $resolved = $this->resolver->resolve($provider);
        } catch (OauthDiscoveryFailedException $e) {
            return $this->fail($state['ru'] ?? null, $verification, 'oauth_discovery_failed', $e->getMessage());
        }

        try {
            $tokenResponse = Http::asForm()->post($resolved->tokenEndpoint, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $provider->computeRedirectUri(),
                'client_id' => $provider->client_id,
                'client_secret' => $provider->encrypted_client_secret,
            ]);
        } catch (ConnectionException|RequestException|Throwable $e) {
            return $this->fail($state['ru'] ?? null, $verification, 'oauth_token_exchange_failed', $e->getMessage());
        }
        if (! $tokenResponse->successful()) {
            return $this->fail($state['ru'] ?? null, $verification, 'oauth_token_exchange_failed', 'Token endpoint returned HTTP '.$tokenResponse->status());
        }
        $tokenBody = $tokenResponse->json();
        if (! is_array($tokenBody) || ! is_string($tokenBody['access_token'] ?? null)) {
            return $this->fail($state['ru'] ?? null, $verification, 'oauth_token_exchange_failed', 'Token endpoint returned no access_token.');
        }

        // TODO(AU-6.1): for preset providers (Google/Apple/Microsoft) the
        // id_token is the authoritative source of `sub` + `email_verified`.
        // Validate the JWS signature against the provider's JWKS before
        // trusting userinfo. Tracked for v0.4.0-stable.
        $userinfo = $this->fetchUserinfo($resolved, $tokenBody);
        if ($userinfo === null) {
            return $this->fail($state['ru'] ?? null, $verification, 'oauth_userinfo_failed', 'userinfo lookup returned no data.');
        }

        $providerUserId = (string) ($userinfo['sub'] ?? $userinfo['id'] ?? '');
        if ($providerUserId === '') {
            return $this->fail($state['ru'] ?? null, $verification, 'oauth_provider_user_id_missing', 'userinfo response had no sub/id.');
        }

        $mapped = $this->applyAttributeMapping($resolved->attributeMapping, $userinfo);
        $emailAddress = isset($mapped['email']) ? strtolower((string) $mapped['email']) : null;
        $emailVerified = (bool) ($mapped['email_verified'] ?? false);

        if ($emailAddress !== null && $provider->block_email_subaddresses && str_contains(explode('@', $emailAddress, 2)[0] ?? '', '+')) {
            return $this->fail($state['ru'] ?? null, $verification, 'email_subaddress_blocked', 'Email subaddresses are blocked on this provider.');
        }

        $signInAttempt = (string) ($state['ak'] ?? '') === 'sign_in'
            ? SignInAttempt::query()->withoutGlobalScopes()->where('id', (string) ($state['a'] ?? ''))->first()
            : null;

        $result = DB::transaction(function () use ($env, $provider, $providerUserId, $emailAddress, $emailVerified, $mapped, $tokenBody): array {
            $existingExt = ExternalAccount::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->where('oauth_provider_id', $provider->id)
                ->where('provider_user_id', $providerUserId)
                ->first();

            if ($existingExt !== null) {
                if (! $provider->allow_sign_in) {
                    return ['error' => 'sign_in_disabled'];
                }
                $existingExt->forceFill([
                    'encrypted_access_token' => (string) $tokenBody['access_token'],
                    'encrypted_refresh_token' => isset($tokenBody['refresh_token']) ? (string) $tokenBody['refresh_token'] : null,
                    'encrypted_id_token' => isset($tokenBody['id_token']) ? (string) $tokenBody['id_token'] : null,
                    'access_token_expires_at' => isset($tokenBody['expires_in']) ? now()->addSeconds((int) $tokenBody['expires_in']) : null,
                    'last_signed_in_at' => now(),
                    'public_metadata' => $mapped,
                ])->save();
                $user = User::query()->withoutGlobalScopes()->where('id', $existingExt->user_id)->first();

                return ['user' => $user, 'external_account' => $existingExt, 'created' => false];
            }

            $user = null;
            if ($emailAddress !== null) {
                $emailRow = EmailAddress::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->where('email_address', $emailAddress)
                    ->first();
                if ($emailRow !== null) {
                    $user = User::query()->withoutGlobalScopes()->where('id', $emailRow->user_id)->first();
                }
            }

            if ($user === null) {
                if (! $provider->allow_sign_up) {
                    return ['error' => 'sign_up_disabled'];
                }
                $user = User::query()->withoutGlobalScopes()->create([
                    'environment_id' => $env->id,
                    'first_name' => $mapped['first_name'] ?? null,
                    'last_name' => $mapped['last_name'] ?? null,
                    'image_url' => $mapped['image_url'] ?? null,
                    'username' => $mapped['username'] ?? null,
                ]);
                if ($emailAddress !== null) {
                    EmailAddress::query()->withoutGlobalScopes()->create([
                        'environment_id' => $env->id,
                        'user_id' => $user->id,
                        'email_address' => $emailAddress,
                        'verified_at' => $emailVerified ? now() : null,
                        'is_primary' => true,
                    ]);
                }
            } elseif (! $provider->allow_sign_in) {
                return ['error' => 'sign_in_disabled'];
            }

            $external = ExternalAccount::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'user_id' => $user->id,
                'oauth_provider_id' => $provider->id,
                'provider_user_id' => $providerUserId,
                'email_address' => $emailAddress,
                'verified' => $emailVerified,
                'scopes' => isset($tokenBody['scope']) ? explode(' ', (string) $tokenBody['scope']) : [],
                'public_metadata' => $mapped,
                'encrypted_access_token' => (string) $tokenBody['access_token'],
                'encrypted_refresh_token' => isset($tokenBody['refresh_token']) ? (string) $tokenBody['refresh_token'] : null,
                'encrypted_id_token' => isset($tokenBody['id_token']) ? (string) $tokenBody['id_token'] : null,
                'access_token_expires_at' => isset($tokenBody['expires_in']) ? now()->addSeconds((int) $tokenBody['expires_in']) : null,
                'linked_at' => now(),
                'last_signed_in_at' => now(),
            ]);

            if ($emailAddress !== null) {
                EmailAddress::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->where('email_address', $emailAddress)
                    ->update(['linked_to_external_account_id' => $external->id]);
            }

            return ['user' => $user, 'external_account' => $external, 'created' => true];
        });

        if (isset($result['error'])) {
            return $this->fail($state['ru'] ?? null, $verification, $result['error'], 'OAuth flow rejected by provider policy.');
        }

        $verification->forceFill([
            'status' => Verification::STATUS_VERIFIED,
            'verified_at' => now(),
        ])->save();

        Challenge::query()->withoutGlobalScopes()
            ->where('verification_id', $verification->id)
            ->update(['status' => Challenge::STATUS_VERIFIED]);

        if ($signInAttempt !== null && $result['user'] !== null) {
            $session = Session::query()->withoutGlobalScopes()->create([
                'environment_id' => $signInAttempt->environment_id,
                'client_id' => $signInAttempt->client_id,
                'user_id' => $result['user']->id,
                'status' => Session::STATUS_ACTIVE,
                'was_test' => (bool) $signInAttempt->was_test,
            ]);
            $signInAttempt->forceFill([
                'status' => SignInAttempt::STATUS_COMPLETE,
                'created_session_id' => $session->id,
            ])->save();
        }

        Log::info('audit:fapi.oauth_callback.success', [
            'environment_id' => $env->id,
            'oauth_provider_id' => $provider->id,
            'user_id' => $result['user']?->id,
            'external_account_id' => $result['external_account']?->id,
            'created' => $result['created'] ?? false,
        ]);

        if (($result['created'] ?? false) === true && $result['external_account'] instanceof ExternalAccount) {
            app(Emitter::class)->emit(
                'externalAccount.connected',
                ExternalAccountResource::from($result['external_account'], $provider),
                $env,
            );
        }

        $redirect = (string) ($state['ruc'] ?? $state['ru'] ?? '/');

        return redirect()->away($redirect);
    }

    /**
     * @param  array<string, mixed>  $tokenBody
     * @return array<string, mixed>|null
     */
    private function fetchUserinfo(ResolvedProvider $resolved, array $tokenBody): ?array
    {
        $accessToken = (string) $tokenBody['access_token'];

        // Apple sends every claim back in the id_token; the userinfo
        // endpoint is the token endpoint as a sentinel — short-circuit
        // and parse the id_token if present.
        if ($resolved->userinfoEndpoint === $resolved->tokenEndpoint && isset($tokenBody['id_token'])) {
            return $this->decodeIdTokenClaims((string) $tokenBody['id_token']);
        }

        $request = Http::withHeaders($resolved->userinfoAuth === 'bearer'
            ? ['Authorization' => 'Bearer '.$accessToken, 'Accept' => 'application/json']
            : ['Accept' => 'application/json']);

        try {
            $response = $resolved->userinfoMethod === 'POST'
                ? $request->asForm()->post($resolved->userinfoEndpoint, $resolved->userinfoAuth === 'query' ? ['access_token' => $accessToken] : [])
                : $request->get($resolved->userinfoEndpoint, $resolved->userinfoAuth === 'query' ? ['access_token' => $accessToken] : []);
        } catch (ConnectionException|RequestException|Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }
        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeIdTokenClaims(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return null;
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, string>  $mapping  e.g. `{ "email" => "email", "first_name" => "given_name" }`
     * @param  array<string, mixed>  $userinfo
     * @return array<string, mixed>
     */
    private function applyAttributeMapping(array $mapping, array $userinfo): array
    {
        $out = [];
        foreach ($mapping as $shape => $claim) {
            if (! is_string($claim) || $claim === '') {
                continue;
            }
            $value = $userinfo;
            foreach (explode('.', $claim) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if ($value !== null) {
                $out[$shape] = $value;
            }
        }

        return $out;
    }

    private function fail(?string $redirectUrl, ?Verification $verification, string $code, string $message): RedirectResponse
    {
        if ($verification !== null) {
            $verification->forceFill([
                'status' => Verification::STATUS_FAILED,
                'error_code' => $code,
                'error_message' => $message,
            ])->save();
            Challenge::query()->withoutGlobalScopes()
                ->where('verification_id', $verification->id)
                ->update([
                    'status' => Challenge::STATUS_FAILED,
                    'error_code' => $code,
                    'error_message' => $message,
                ]);
        }

        Log::info('audit:fapi.oauth_callback.failed', [
            'code' => $code,
            'message' => $message,
            'verification_id' => $verification?->id,
        ]);

        $target = $redirectUrl !== null && $redirectUrl !== ''
            ? $redirectUrl.(str_contains($redirectUrl, '?') ? '&' : '?').'__authn_error='.urlencode($code)
            : '/?__authn_error='.urlencode($code);

        return redirect()->away($target);
    }
}
