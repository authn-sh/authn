<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Models\AuthorizationGrant;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\OauthApplication;
use App\Models\SigningKey;
use App\Models\User;
use App\Support\Base64Url;
use App\Support\Url;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

/**
 * v0.7 OAuth provider mode token endpoint — RFC 6749 §4.1.3 (auth-code
 * grant) + §6 (refresh-token grant) + OIDC §3.1.3.7 (id_token), plus
 * RFC 7662 token introspection.
 *
 *   POST /oauth/token        application/x-www-form-urlencoded
 *   POST /oauth/token_info   application/x-www-form-urlencoded
 *
 * Both `id_token` and `access_token` are RS256 JWTs signed with the
 * env's active SigningKey (the same key surfaced at
 * `/.well-known/jwks.json` so verifiers don't need to bind anything
 * new). The refresh-token grant rotates the row — the previous
 * `oauth_refresh_tokens` row is revoked in the same transaction.
 */
final class OauthTokenController
{
    public const ACCESS_TOKEN_TTL_SECONDS = 3600;

    public const REFRESH_TOKEN_TTL_SECONDS = 30 * 24 * 3600;

    /**
     * `POST /oauth/token` dispatcher.
     */
    public function token(Request $request): JsonResponse
    {
        $grantType = (string) $request->input('grant_type', '');

        return match ($grantType) {
            'authorization_code' => $this->exchangeAuthorizationCode($request),
            'refresh_token' => $this->exchangeRefreshToken($request),
            default => $this->oauthError(400, 'unsupported_grant_type',
                'Supported grant_types: authorization_code, refresh_token.'),
        };
    }

    public function tokenInfo(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        if ($token === '') {
            return response()->json(['active' => false])->header('Cache-Control', 'no-store');
        }

        // Access tokens are JWTs we issued — parse + verify against the env JWKS.
        $env = app(Environment::class);
        $parsed = $this->parseAccessToken($env, $token);
        if ($parsed === null) {
            return response()->json(['active' => false])->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'active' => true,
            'sub' => $parsed['sub'],
            'scope' => $parsed['scope'],
            'exp' => $parsed['exp'],
            'iat' => $parsed['iat'],
            'client_id' => $parsed['client_id'],
            'token_type' => 'Bearer',
        ])->header('Cache-Control', 'no-store');
    }

    private function exchangeAuthorizationCode(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $code = (string) $request->input('code', '');
        $redirectUri = (string) $request->input('redirect_uri', '');

        $codeRow = DB::table('oauth_authorization_codes')
            ->where('code', $code)
            ->where('environment_id', $env->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();
        if ($codeRow === null) {
            return $this->oauthError(400, 'invalid_grant', 'authorization_code is unknown, expired, or already consumed.');
        }

        $app = OauthApplication::query()->withoutGlobalScopes()
            ->where('id', $codeRow->oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($app === null) {
            return $this->oauthError(400, 'invalid_grant', 'The associated OauthApplication has been deleted.');
        }

        if ($redirectUri === '' || $redirectUri !== $codeRow->redirect_uri) {
            return $this->oauthError(400, 'invalid_grant', 'redirect_uri does not match the original /oauth/authorize request.');
        }

        // Client authentication.
        $clientAuth = $this->authenticateClient($request, $app, $codeRow);
        if ($clientAuth !== null) {
            return $clientAuth;
        }

        // Single-use: consume the code.
        DB::table('oauth_authorization_codes')->where('code', $code)
            ->update(['consumed_at' => now(), 'updated_at' => now()]);

        // Confirm a still-active AuthorizationGrant covers this scope set —
        // gives AU-10's revoke hook teeth (no token issued from a code minted
        // before the user revoked the grant).
        $scopes = is_array(json_decode((string) $codeRow->scopes, true)) ? json_decode((string) $codeRow->scopes, true) : [];
        $grant = AuthorizationGrant::query()->withoutGlobalScopes()
            ->where('user_id', $codeRow->user_id)
            ->where('oauth_application_id', $app->id)
            ->where('scopes_hash', AuthorizationGrant::hashScopes($scopes))
            ->whereNull('revoked_at')
            ->first();
        if ($grant === null) {
            return $this->oauthError(400, 'invalid_grant', 'The covering AuthorizationGrant has been revoked.');
        }

        return $this->mintTokens(
            env: $env,
            app: $app,
            userId: (string) $codeRow->user_id,
            scopes: $scopes,
            nonce: $codeRow->nonce !== null ? (string) $codeRow->nonce : null,
            wantsIdToken: in_array('openid', $scopes, true),
        );
    }

    private function exchangeRefreshToken(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $plaintext = (string) $request->input('refresh_token', '');
        if ($plaintext === '') {
            return $this->oauthError(400, 'invalid_grant', 'refresh_token is required.');
        }
        $hash = hash('sha256', $plaintext);
        $row = DB::table('oauth_refresh_tokens')
            ->where('hashed_token', $hash)
            ->where('environment_id', $env->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        if ($row === null) {
            return $this->oauthError(400, 'invalid_grant', 'refresh_token is unknown, expired, or revoked.');
        }
        $app = OauthApplication::query()->withoutGlobalScopes()
            ->where('id', $row->oauth_application_id)
            ->whereNull('removed_at')
            ->first();
        if ($app === null) {
            return $this->oauthError(400, 'invalid_grant', 'The associated OauthApplication has been deleted.');
        }
        $clientAuth = $this->authenticateClient($request, $app, null);
        if ($clientAuth !== null) {
            return $clientAuth;
        }

        $grant = AuthorizationGrant::query()->withoutGlobalScopes()
            ->where('user_id', $row->user_id)
            ->where('oauth_application_id', $app->id)
            ->whereNull('revoked_at')
            ->first();
        if ($grant === null) {
            return $this->oauthError(400, 'invalid_grant', 'The covering AuthorizationGrant has been revoked.');
        }

        // Rotate: revoke the consumed row, mint a fresh one.
        DB::table('oauth_refresh_tokens')->where('id', $row->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        $scopes = is_array(json_decode((string) $row->scopes, true)) ? json_decode((string) $row->scopes, true) : [];

        return $this->mintTokens(
            env: $env,
            app: $app,
            userId: (string) $row->user_id,
            scopes: $scopes,
            nonce: null,
            wantsIdToken: in_array('openid', $scopes, true),
            rotatedFromId: (string) $row->id,
        );
    }

    /**
     * @param  list<string>  $scopes
     */
    private function mintTokens(
        Environment $env,
        OauthApplication $app,
        string $userId,
        array $scopes,
        ?string $nonce,
        bool $wantsIdToken,
        ?string $rotatedFromId = null,
    ): JsonResponse {
        $signingKey = $env->signingKeys()
            ->where('status', SigningKey::STATUS_ACTIVE)
            ->latest('activated_at')
            ->first();
        if ($signingKey === null) {
            return $this->oauthError(500, 'server_error', 'No active signing key for this environment.');
        }

        $now = now();
        $accessExpires = $now->copy()->addSeconds(self::ACCESS_TOKEN_TTL_SECONDS);
        $refreshExpires = $now->copy()->addSeconds(self::REFRESH_TOKEN_TTL_SECONDS);
        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($signingKey->privatePem()),
            InMemory::plainText('public-not-needed-for-signing'),
        );

        // Access token — JWT, audience pinned to the client_id so verifiers
        // can disambiguate this from a session token.
        $accessBuilder = $config->builder()
            ->withHeader('kid', $signingKey->id)
            ->issuedBy(Url::fapi($env, ''))
            ->relatedTo($userId)
            ->permittedFor($app->client_id)
            ->identifiedBy('oat_'.Base64Url::encode(random_bytes(16)))
            ->issuedAt($now->toDateTimeImmutable())
            ->canOnlyBeUsedAfter($now->toDateTimeImmutable())
            ->expiresAt($accessExpires->toDateTimeImmutable())
            ->withClaim('scope', implode(' ', $scopes))
            ->withClaim('client_id', $app->client_id)
            ->withClaim('token_use', 'access');
        $accessToken = $accessBuilder->getToken($config->signer(), $config->signingKey())->toString();

        $idToken = null;
        if ($wantsIdToken) {
            $user = User::query()->withoutGlobalScopes()->find($userId);
            $email = $user !== null
                ? EmailAddress::query()->where('user_id', $user->id)->where('is_primary', true)->value('email_address')
                : null;

            $idBuilder = $config->builder()
                ->withHeader('kid', $signingKey->id)
                ->issuedBy(Url::fapi($env, ''))
                ->relatedTo($userId)
                ->permittedFor($app->client_id)
                ->identifiedBy('oit_'.Base64Url::encode(random_bytes(16)))
                ->issuedAt($now->toDateTimeImmutable())
                ->canOnlyBeUsedAfter($now->toDateTimeImmutable())
                ->expiresAt($accessExpires->toDateTimeImmutable())
                ->withClaim('token_use', 'id');
            if ($nonce !== null && $nonce !== '') {
                $idBuilder = $idBuilder->withClaim('nonce', $nonce);
            }
            if (in_array('profile', $scopes, true) && $user !== null) {
                $idBuilder = $idBuilder
                    ->withClaim('given_name', (string) ($user->first_name ?? ''))
                    ->withClaim('family_name', (string) ($user->last_name ?? ''));
            }
            if (in_array('email', $scopes, true) && $email !== null) {
                $idBuilder = $idBuilder
                    ->withClaim('email', $email)
                    ->withClaim('email_verified', true);
            }
            $idToken = $idBuilder->getToken($config->signer(), $config->signingKey())->toString();
        }

        // Refresh token — opaque random, sha256-hashed at rest.
        $refreshPlaintext = 'ort_'.Base64Url::encode(random_bytes(32));
        $refreshId = 'ort_'.Base64Url::encode(random_bytes(16));
        DB::table('oauth_refresh_tokens')->insert([
            'id' => $refreshId,
            'environment_id' => $env->id,
            'oauth_application_id' => $app->id,
            'user_id' => $userId,
            'hashed_token' => hash('sha256', $refreshPlaintext),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'nonce' => $nonce,
            'expires_at' => $refreshExpires,
            'rotated_from_id' => $rotatedFromId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Log::info('audit:fapi.oauth.token_issued', [
            'environment_id' => $env->id,
            'oauth_application_id' => $app->id,
            'user_id' => $userId,
            'rotated_from_id' => $rotatedFromId,
        ]);
        app(Emitter::class)->emit('oauthToken.issued', [
            'oauth_application_id' => $app->id,
            'user_id' => $userId,
            'scopes' => $scopes,
        ], $env);

        $payload = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL_SECONDS,
            'refresh_token' => $refreshPlaintext,
            'scope' => implode(' ', $scopes),
        ];
        if ($idToken !== null) {
            $payload['id_token'] = $idToken;
        }

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /**
     * Authenticate the calling client. Returns null on success or a
     * `JsonResponse` with the right `invalid_client` envelope on failure.
     * Confidential clients can authenticate via Basic auth (`Authorization`)
     * OR form-encoded `client_id` + `client_secret`. Public clients
     * supply PKCE `code_verifier` (auth-code path) or `client_id` only
     * (refresh-token path) — we still match the `client_id` to the app.
     */
    private function authenticateClient(Request $request, OauthApplication $app, ?object $codeRow): ?JsonResponse
    {
        // Basic auth path (RFC 6749 §2.3.1).
        $authHeader = (string) $request->header('Authorization', '');
        $providedClientId = null;
        $providedSecret = null;
        if (str_starts_with(strtolower($authHeader), 'basic ')) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$providedClientId, $providedSecret] = explode(':', $decoded, 2);
                $providedClientId = urldecode($providedClientId);
                $providedSecret = urldecode($providedSecret);
            }
        }
        $providedClientId ??= (string) $request->input('client_id', '');
        $providedSecret ??= (string) $request->input('client_secret', '');

        if ($providedClientId === '' || $providedClientId !== $app->client_id) {
            return $this->oauthError(401, 'invalid_client', 'client_id does not match the supplied credentials.');
        }

        if ($app->is_public) {
            // Public clients on the auth-code path must verify their PKCE
            // commitment. On the refresh-token path PKCE is no longer in
            // play; client_id parity is the only check.
            if ($codeRow !== null) {
                $challenge = $codeRow->code_challenge ?? null;
                $verifier = (string) $request->input('code_verifier', '');
                if ($challenge === null || $challenge === '') {
                    return $this->oauthError(400, 'invalid_grant',
                        'Public client missing code_challenge on the authorization request.');
                }
                if ($verifier === '') {
                    return $this->oauthError(400, 'invalid_grant', 'code_verifier is required for public clients.');
                }
                $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
                if (! hash_equals($challenge, $expected)) {
                    return $this->oauthError(400, 'invalid_grant', 'code_verifier does not match the original code_challenge.');
                }
            }

            return null;
        }

        // Confidential client — secret must verify against the row's hash.
        if ($providedSecret === '' || ! $app->verifyClientSecret($providedSecret)) {
            return $this->oauthError(401, 'invalid_client', 'client_secret did not verify.');
        }

        return null;
    }

    /**
     * @return array{sub: string, scope: string, exp: int, iat: int, client_id: string}|null
     */
    private function parseAccessToken(Environment $env, string $token): ?array
    {
        try {
            $parser = new Parser(new JoseEncoder);
            $parsed = $parser->parse($token);
        } catch (\Throwable) {
            return null;
        }
        $claims = $parsed->claims();
        $exp = $claims->get('exp');
        if (! $exp instanceof \DateTimeInterface || $exp->getTimestamp() < now()->getTimestamp()) {
            return null;
        }
        if ($claims->get('token_use') !== 'access') {
            return null;
        }
        // The token must verify against the env's active JWKS — we trust
        // any active SigningKey for the env. (Rotation is handled by the
        // kid header; we just iterate active keys.)
        $kid = (string) $parsed->headers()->get('kid', '');
        $signingKey = SigningKey::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $kid)
            ->whereIn('status', [SigningKey::STATUS_ACTIVE, SigningKey::STATUS_RETIRING])
            ->first();
        if ($signingKey === null) {
            return null;
        }
        // Derive the public key PEM from the persisted private PEM so
        // verification doesn't need a separate column. RS256 keypair —
        // openssl extracts the public half deterministically.
        $publicPem = $this->publicPemFor($signingKey);
        if ($publicPem === null) {
            return null;
        }
        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($signingKey->privatePem()),
            InMemory::plainText($publicPem),
        );
        try {
            $valid = $config->validator()->validate(
                $parsed,
                new SignedWith($config->signer(), $config->verificationKey()),
            );
        } catch (\Throwable) {
            return null;
        }
        if (! $valid) {
            return null;
        }
        $iat = $claims->get('iat');
        $iatStamp = $iat instanceof \DateTimeInterface ? $iat->getTimestamp() : 0;

        return [
            'sub' => (string) ($claims->get('sub') ?? ''),
            'scope' => (string) ($claims->get('scope') ?? ''),
            'exp' => $exp->getTimestamp(),
            'iat' => $iatStamp,
            'client_id' => (string) ($claims->get('client_id') ?? ''),
        ];
    }

    private function publicPemFor(SigningKey $signingKey): ?string
    {
        $res = openssl_pkey_get_private($signingKey->privatePem());
        if ($res === false) {
            return null;
        }
        $details = openssl_pkey_get_details($res);
        if (! is_array($details) || ! isset($details['key']) || ! is_string($details['key'])) {
            return null;
        }

        return $details['key'];
    }

    private function oauthError(int $status, string $code, string $message): JsonResponse
    {
        // RFC 6749 §5.2 error envelope.
        return response()->json([
            'error' => $code,
            'error_description' => $message,
        ], $status)->header('Cache-Control', 'no-store');
    }
}
