<?php

declare(strict_types=1);

namespace App\Auth\EnterpriseSso;

use App\Models\EnterpriseConnection;
use App\Support\Base64Url;
use App\Support\Url;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as Rs256;
use Lcobucci\JWT\Signer\Rsa\Sha384 as Rs384;
use Lcobucci\JWT\Signer\Rsa\Sha512 as Rs512;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Throwable;

/**
 * OIDC engine for enterprise connections. Mirrors `app/Auth/Oauth` but
 * keyed by `EnterpriseConnection` (instance-wide or org-scoped) rather
 * than the v0.4 `OauthProvider`. Reuses the same JWS-via-JWKS, discovery
 * + PKCE-S256 + nonce-round-trip patterns.
 *
 * Three public operations:
 *   - `discover(EnterpriseConnection)` — fetch + cache `.well-known/openid-configuration`.
 *   - `buildAuthorizeUrl(EnterpriseConnection, $state, $nonce)` — emit the IdP authorize URL with PKCE.
 *   - `exchangeCode(EnterpriseConnection, $code, $codeVerifier)` — code → tokens.
 *   - `verifyIdToken(EnterpriseConnection, $idToken, $nonce)` — JWS verify against JWKS + claim checks.
 */
final class OidcConnectionService
{
    private const DISCOVERY_CACHE_TTL_SECONDS = 300;

    private const JWKS_CACHE_TTL_SECONDS = 3600;

    private readonly Parser $parser;

    private readonly Validator $validator;

    public function __construct()
    {
        $this->parser = new Parser(new JoseEncoder);
        $this->validator = new Validator;
    }

    /**
     * Fetch `<oidc_issuer>/.well-known/openid-configuration` (or
     * `oidc_discovery_endpoint` when set explicitly) and return the
     * relevant subset. Cached 5 minutes per connection id; cache hits
     * never touch the network.
     *
     * @return array{
     *     authorization_endpoint:string,
     *     token_endpoint:string,
     *     userinfo_endpoint:?string,
     *     jwks_uri:?string,
     *     id_token_signing_algs:list<string>,
     * }
     *
     * @throws OidcDiscoveryException
     */
    public function discover(EnterpriseConnection $conn): array
    {
        $url = $this->discoveryUrl($conn);
        $cacheKey = 'oidc:enterprise:discovery:'.$conn->id;

        return Cache::remember($cacheKey, self::DISCOVERY_CACHE_TTL_SECONDS, function () use ($url, $conn): array {
            try {
                $response = Http::acceptJson()->timeout(10)->get($url);
            } catch (ConnectionException|RequestException|Throwable $e) {
                throw new OidcDiscoveryException($conn->id, $url, $e->getMessage());
            }

            if (! $response->successful()) {
                throw new OidcDiscoveryException($conn->id, $url, "HTTP {$response->status()}");
            }

            $document = $response->json();
            if (! is_array($document)) {
                throw new OidcDiscoveryException($conn->id, $url, 'Discovery body was not a JSON object');
            }

            foreach (['authorization_endpoint', 'token_endpoint'] as $required) {
                if (! is_string($document[$required] ?? null) || $document[$required] === '') {
                    throw new OidcDiscoveryException($conn->id, $url, "Missing {$required}");
                }
            }
            $algs = $document['id_token_signing_alg_values_supported'] ?? ['RS256'];
            if (! is_array($algs)) {
                $algs = ['RS256'];
            }
            $algs = array_values(array_filter(array_map('strval', $algs), fn ($v) => $v !== ''));

            return [
                'authorization_endpoint' => (string) $document['authorization_endpoint'],
                'token_endpoint' => (string) $document['token_endpoint'],
                'userinfo_endpoint' => is_string($document['userinfo_endpoint'] ?? null)
                    ? (string) $document['userinfo_endpoint']
                    : null,
                'jwks_uri' => is_string($document['jwks_uri'] ?? null) ? (string) $document['jwks_uri'] : null,
                'id_token_signing_algs' => $algs,
            ];
        });
    }

    /**
     * Build the IdP authorize URL with `state`, `nonce`, and a freshly
     * generated PKCE-S256 challenge. The caller persists `$codeVerifier`
     * on the parent Challenge row (callers in AU-7) and feeds it back
     * to `exchangeCode()` on the callback leg.
     *
     * @return array{authorize_url:string, code_verifier:string}
     */
    public function buildAuthorizeUrl(EnterpriseConnection $conn, string $state, string $nonce): array
    {
        $endpoints = $this->discover($conn);

        $codeVerifier = Base64Url::encode(random_bytes(32));
        $codeChallenge = Base64Url::encode(hash('sha256', $codeVerifier, true));

        $scopes = is_array($conn->oidc_scopes) && $conn->oidc_scopes !== []
            ? $conn->oidc_scopes
            : ['openid', 'email', 'profile'];

        $params = [
            'response_type' => 'code',
            'client_id' => (string) $conn->oidc_client_id,
            'redirect_uri' => $this->redirectUri($conn),
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return [
            'authorize_url' => $endpoints['authorization_endpoint'].'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            'code_verifier' => $codeVerifier,
        ];
    }

    /**
     * Exchange the authorization code for tokens. Returns the raw token
     * response — callers handle `id_token` extraction via `verifyIdToken`.
     *
     * @return array{access_token:?string, id_token:?string, refresh_token:?string, token_type:?string, expires_in:?int}
     *
     * @throws OidcTokenExchangeException
     */
    public function exchangeCode(EnterpriseConnection $conn, string $code, string $codeVerifier): array
    {
        $endpoints = $this->discover($conn);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post($endpoints['token_endpoint'], [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri($conn),
                    'client_id' => (string) $conn->oidc_client_id,
                    'client_secret' => (string) $conn->oidc_client_secret,
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (ConnectionException|RequestException|Throwable $e) {
            throw new OidcTokenExchangeException($conn->id, $e->getMessage());
        }

        if (! $response->successful()) {
            throw new OidcTokenExchangeException($conn->id, "Token exchange returned HTTP {$response->status()}");
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new OidcTokenExchangeException($conn->id, 'Token response was not a JSON object');
        }

        return [
            'access_token' => is_string($body['access_token'] ?? null) ? $body['access_token'] : null,
            'id_token' => is_string($body['id_token'] ?? null) ? $body['id_token'] : null,
            'refresh_token' => is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : null,
            'token_type' => is_string($body['token_type'] ?? null) ? $body['token_type'] : null,
            'expires_in' => is_int($body['expires_in'] ?? null) ? $body['expires_in'] : null,
        ];
    }

    /**
     * Verify a received id_token: parse, fetch JWKS (cached 1h), check
     * the JWS signature against the matching JWK, validate `iss` / `aud`
     * / `nonce` / `exp` / `iat`. Returns the verified claims or null on
     * any failure.
     *
     * @return array<string,mixed>|null
     */
    public function verifyIdToken(EnterpriseConnection $conn, string $idToken, string $expectedNonce): ?array
    {
        $endpoints = $this->discover($conn);
        if ($endpoints['jwks_uri'] === null) {
            return null;
        }

        try {
            $token = $this->parser->parse($idToken);
        } catch (Throwable) {
            return null;
        }
        if (! $token instanceof Plain) {
            return null;
        }

        $alg = (string) $token->headers()->get('alg', '');
        if (! in_array($alg, $endpoints['id_token_signing_algs'], true)) {
            return null;
        }
        $kid = $token->headers()->get('kid');

        $jwks = $this->fetchJwks($conn->id, $endpoints['jwks_uri']);
        if ($jwks === null) {
            return null;
        }
        $pem = $this->pickKey($jwks, is_string($kid) ? $kid : null);
        if ($pem === null) {
            return null;
        }

        $signer = match ($alg) {
            'RS256' => new Rs256,
            'RS384' => new Rs384,
            'RS512' => new Rs512,
            default => null,
        };
        if ($signer === null) {
            return null;
        }

        $constraints = [new SignedWith($signer, InMemory::plainText($pem))];
        if (is_string($conn->oidc_issuer) && $conn->oidc_issuer !== '') {
            $constraints[] = new IssuedBy($conn->oidc_issuer);
        }
        if (is_string($conn->oidc_client_id) && $conn->oidc_client_id !== '') {
            $constraints[] = new PermittedFor($conn->oidc_client_id);
        }
        $constraints[] = new LooseValidAt(SystemClock::fromUTC());

        if (! $this->validator->validate($token, ...$constraints)) {
            return null;
        }

        $claims = $token->claims()->all();
        if (! is_array($claims)) {
            return null;
        }
        if (($claims['nonce'] ?? null) !== $expectedNonce) {
            return null;
        }

        return $claims;
    }

    /**
     * The redirect URI the IdP posts the authorization code back to.
     * Stable per env + connection so operators can paste it into the
     * IdP's allow-list when configuring the connection.
     */
    public function redirectUri(EnterpriseConnection $conn): string
    {
        return Url::fapi($conn->environment, '/v1/enterprise-sso-callback/'.$conn->id);
    }

    private function discoveryUrl(EnterpriseConnection $conn): string
    {
        if (is_string($conn->oidc_discovery_endpoint) && $conn->oidc_discovery_endpoint !== '') {
            return $conn->oidc_discovery_endpoint;
        }
        $issuer = rtrim((string) $conn->oidc_issuer, '/');

        return $issuer.'/.well-known/openid-configuration';
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchJwks(string $connectionId, string $jwksUri): ?array
    {
        $cacheKey = 'oidc:enterprise:jwks:'.$connectionId.':'.hash('sha256', $jwksUri);

        return Cache::remember($cacheKey, self::JWKS_CACHE_TTL_SECONDS, function () use ($jwksUri): ?array {
            try {
                $response = Http::timeout(5)->get($jwksUri);
            } catch (ConnectionException|RequestException|Throwable) {
                return null;
            }
            if (! $response->successful()) {
                return null;
            }
            $body = $response->json();
            $keys = $body['keys'] ?? null;
            if (! is_array($keys)) {
                return null;
            }

            return array_values(array_filter($keys, 'is_array'));
        });
    }

    /**
     * @param  array<int,array<string,mixed>>  $jwks
     */
    private function pickKey(array $jwks, ?string $kid): ?string
    {
        foreach ($jwks as $jwk) {
            if ($kid !== null && ($jwk['kid'] ?? null) !== $kid) {
                continue;
            }
            if (($jwk['kty'] ?? null) !== 'RSA') {
                continue;
            }
            $n = $jwk['n'] ?? null;
            $e = $jwk['e'] ?? null;
            if (! is_string($n) || ! is_string($e)) {
                continue;
            }

            return $this->jwkToPem($n, $e);
        }

        return null;
    }

    private function jwkToPem(string $nB64Url, string $eB64Url): string
    {
        $n = Base64Url::decode($nB64Url);
        $e = Base64Url::decode($eB64Url);

        $modulus = $this->asn1Integer($n);
        $exponent = $this->asn1Integer($e);
        $rsaKey = $this->asn1Sequence($modulus.$exponent);
        $bitString = "\x00".$rsaKey;
        $bitStringWrap = "\x03".$this->asn1Length(strlen($bitString)).$bitString;
        $algIdentifier = $this->asn1Sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $spki = $this->asn1Sequence($algIdentifier.$bitStringWrap);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private function asn1Integer(string $bytes): string
    {
        if ($bytes !== '' && (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->asn1Length(strlen($bytes)).$bytes;
    }

    private function asn1Sequence(string $bytes): string
    {
        return "\x30".$this->asn1Length(strlen($bytes)).$bytes;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
