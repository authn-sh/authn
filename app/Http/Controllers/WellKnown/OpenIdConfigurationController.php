<?php

declare(strict_types=1);

namespace App\Http\Controllers\WellKnown;

use App\Models\Environment;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /.well-known/openid-configuration` — OIDC discovery document.
 *
 * v0.7 lit up the full IdP role: the authorize / token / userinfo
 * endpoints are now part of the discovery payload, alongside the JWKS
 * URL and signing algorithms that downstream JWT verifiers consume.
 */
final class OpenIdConfigurationController
{
    public function __invoke(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $issuer = Url::fapi($env, '');

        return response()->json([
            'issuer' => $issuer,
            'jwks_uri' => Url::fapi($env, '/.well-known/jwks.json'),
            'authorization_endpoint' => Url::fapi($env, '/oauth/authorize'),
            'token_endpoint' => Url::fapi($env, '/oauth/token'),
            'userinfo_endpoint' => Url::fapi($env, '/oauth/userinfo'),
            'introspection_endpoint' => Url::fapi($env, '/oauth/token_info'),
            'response_types_supported' => ['code', 'id_token'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
                'none', // public PKCE clients.
            ],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => [
                'iss', 'sub', 'aud', 'exp', 'iat', 'nbf', 'jti', 'azp', 'nonce',
                'name', 'given_name', 'family_name', 'preferred_username', 'picture',
                'email', 'email_verified',
            ],
        ])->header('Cache-Control', 'public, max-age=3600, must-revalidate');
    }
}
