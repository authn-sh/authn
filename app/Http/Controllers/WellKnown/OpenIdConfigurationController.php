<?php

declare(strict_types=1);

namespace App\Http\Controllers\WellKnown;

use App\Models\Environment;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /.well-known/openid-configuration` — minimal v0.1 OIDC discovery.
 *
 * Just enough for downstream JWT verifiers (Supabase, Hasura, custom
 * middleware) to discover the issuer + JWKS URL + signing algorithms.
 * The full IdP role (authorization_endpoint, token_endpoint, userinfo)
 * lands in v0.7 when authn.sh starts acting as an OAuth provider in
 * its own right.
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
            'response_types_supported' => ['id_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'claims_supported' => ['iss', 'sub', 'sid', 'aud', 'exp', 'iat', 'nbf', 'jti', 'azp', 'v', 'fva', 'sts'],
            'token_endpoint_auth_methods_supported' => [],
        ])->header('Cache-Control', 'public, max-age=300, must-revalidate');
    }
}
