<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\SigningKey;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

/**
 * v0.7 OAuth provider mode — OIDC userinfo endpoint.
 *
 *   GET /oauth/userinfo
 *
 * Bearer-authenticated by an `access_token` minted by AU-7's
 * `/oauth/token`. Returns User claims filtered by the granted `scope`
 * baked into the access token. `openid` is always required (rejects
 * with 401 if absent — RFC 7662 §3.1).
 *
 * Scope → claim mapping:
 *   openid          → sub
 *   profile         → name, given_name, family_name, preferred_username, picture
 *   email           → email, email_verified
 *   other / custom  → reserved for v0.8 (per-env mapping).
 */
final class OauthUserinfoController
{
    public function __invoke(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->unauthorized('missing_token', 'Bearer access_token required.');
        }

        $claims = $this->parseAccessToken($env, $token);
        if ($claims === null) {
            return $this->unauthorized('invalid_token', 'Bearer access_token is invalid or expired.');
        }

        $scopes = $this->scopeList($claims['scope']);
        if (! in_array('openid', $scopes, true)) {
            // OIDC userinfo requires `openid` — without it the access token
            // is for a different audience (custom API scope).
            return $this->unauthorized('insufficient_scope', 'openid scope is required to call /oauth/userinfo.');
        }

        $user = User::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $claims['sub'])
            ->first();
        if ($user === null) {
            return $this->unauthorized('invalid_token', 'The user referenced by sub no longer exists.');
        }

        $payload = ['sub' => $user->id];

        if (in_array('profile', $scopes, true)) {
            $given = (string) ($user->first_name ?? '');
            $family = (string) ($user->last_name ?? '');
            $payload['given_name'] = $given;
            $payload['family_name'] = $family;
            $payload['name'] = trim($given.' '.$family);
            $payload['preferred_username'] = (string) ($user->username ?? '');
            if (! empty($user->image_url)) {
                $payload['picture'] = (string) $user->image_url;
            }
        }

        if (in_array('email', $scopes, true)) {
            $email = EmailAddress::query()
                ->where('user_id', $user->id)
                ->where('is_primary', true)
                ->first();
            if ($email !== null) {
                $payload['email'] = (string) $email->email_address;
                $payload['email_verified'] = $email->verified_at !== null;
            }
        }

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }
        $value = trim(substr($header, 7));

        return $value === '' ? null : $value;
    }

    /**
     * @return array{sub: string, scope: string, exp: int, iat: int, client_id: string}|null
     */
    private function parseAccessToken(Environment $env, string $token): ?array
    {
        try {
            $parsed = (new Parser(new JoseEncoder))->parse($token);
        } catch (\Throwable) {
            return null;
        }
        $exp = $parsed->claims()->get('exp');
        if (! $exp instanceof \DateTimeInterface || $exp->getTimestamp() < now()->getTimestamp()) {
            return null;
        }
        if ($parsed->claims()->get('token_use') !== 'access') {
            return null;
        }
        $kid = (string) $parsed->headers()->get('kid', '');
        $signingKey = SigningKey::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $kid)
            ->whereIn('status', [SigningKey::STATUS_ACTIVE, SigningKey::STATUS_RETIRING])
            ->first();
        if ($signingKey === null) {
            return null;
        }
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
        $iat = $parsed->claims()->get('iat');

        return [
            'sub' => (string) ($parsed->claims()->get('sub') ?? ''),
            'scope' => (string) ($parsed->claims()->get('scope') ?? ''),
            'exp' => $exp->getTimestamp(),
            'iat' => $iat instanceof \DateTimeInterface ? $iat->getTimestamp() : 0,
            'client_id' => (string) ($parsed->claims()->get('client_id') ?? ''),
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

    /**
     * @return list<string>
     */
    private function scopeList(string $raw): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', trim($raw)) ?: [],
            static fn ($s) => is_string($s) && $s !== '',
        ));
    }

    private function unauthorized(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'error_description' => $message,
        ], 401)->header('Cache-Control', 'no-store');
    }
}
