<?php

declare(strict_types=1);

use App\Auth\Oauth\StateToken;
use App\Models\Client;
use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\OauthProvider;
use App\Models\Project;
use App\Models\SignInAttempt;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Tests\Support\OauthProviderFixtures;

/**
 * AU-6.1: id_token JWS validation. Asserts that when the IdP returns
 * an id_token, security-critical claims (sub, email, email_verified)
 * come from the signed token — userinfo is only trusted for display
 * fields. A tampered userinfo response is overridden by the id_token's
 * signed claim, and an id_token signed with a key not in the JWKS is
 * rejected outright.
 */
function au61MintRsaKeyPair(): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privatePem);
    $details = openssl_pkey_get_details($res);

    return [
        'private_pem' => $privatePem,
        'jwk' => [
            'kty' => 'RSA',
            'kid' => 'test-key-1',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ],
    ];
}

function au61MintIdToken(array $keypair, array $claims, string $issuer, string $audience): string
{
    $sub = $claims['sub'] ?? '';
    unset($claims['sub']);
    $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
        ->withHeader('kid', $keypair['jwk']['kid'])
        ->issuedBy($issuer)
        ->permittedFor($audience)
        ->relatedTo((string) $sub)
        ->issuedAt(new DateTimeImmutable('@'.time()))
        ->expiresAt(new DateTimeImmutable('@'.(time() + 600)))
        ->identifiedBy(bin2hex(random_bytes(16)));
    foreach ($claims as $name => $value) {
        $builder = $builder->withClaim($name, $value);
    }

    return $builder->getToken(new Sha256, InMemory::plainText($keypair['private_pem']))->toString();
}

function au61ReloadFapiRoutes(): void
{
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $appHost = (string) config('authn.app_host');
    $fapi = Route::middleware('fapi');
    if ((string) config('authn.routing_mode') === 'subdomain') {
        $fapi->domain('{env_slug}.'.$appHost);
    } else {
        $fapi->prefix('{env_slug}');
    }
    $fapi->group(base_path('routes/fapi.php'));
}

function au61Env(): Environment
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'http',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
    ]);
    au61ReloadFapiRoutes();
    $project = Project::create(['name' => 'P', 'slug' => 'p-cb-au61']);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'cb',
        'routing_label' => 'cb',
    ]);
}

function au61EnableGoogle(Environment $env, string $clientId = 'goog-client-id'): OauthProvider
{
    return OauthProviderFixtures::configuredPreset($env, 'google', [
        'client_id' => $clientId,
        'encrypted_client_secret' => 'goog-client-secret',
    ]);
}

it('uses id_token sub + email_verified over tampered userinfo', function (): void {
    $env = au61Env();
    $provider = au61EnableGoogle($env);
    $kp = au61MintRsaKeyPair();

    $idToken = au61MintIdToken($kp, [
        'sub' => 'g-AUTHORITATIVE-ID',
        'email' => 'alice@example.com',
        'email_verified' => false,
    ], 'https://accounts.google.com', 'goog-client-id');

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600,
            'id_token' => $idToken,
        ], 200),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response([
            'sub' => 'g-ATTACKER-ID',
            'email' => 'alice@example.com',
            'email_verified' => true,
            'given_name' => 'Alice',
            'family_name' => 'Smith',
        ], 200),
        'www.googleapis.com/oauth2/v3/certs' => Http::response([
            'keys' => [$kp['jwk']],
        ], 200),
    ]);

    $client = Client::create(['environment_id' => $env->id]);
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => 'oauth_google',
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addMinutes(10),
    ]);

    $state = StateToken::mint(
        environmentId: $env->id, providerKey: 'google', verificationId: $verification->id,
        clientId: $client->id, attemptId: $attempt->id, attemptKind: 'sign_in',
        redirectUrl: 'http://app.example.com/sign-in',
        redirectUrlComplete: 'http://app.example.com/dashboard',
        nonce: 'n-1',
    );

    $r = $this->withHeaders(['Host' => 'cb.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('http://cb.authn.local/v1/oauth-callback/google?state='.urlencode($state).'&code=auth-code-1');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->not->toContain('__authn_error');

    $ext = ExternalAccount::query()->withoutGlobalScopes()
        ->where('oauth_provider_id', $provider->id)
        ->first();
    expect($ext)->not->toBeNull();
    expect($ext->provider_user_id)->toBe('g-AUTHORITATIVE-ID')
        ->and($ext->provider_user_id)->not->toBe('g-ATTACKER-ID');

    $user = User::query()->withoutGlobalScopes()->where('id', $ext->user_id)->first();
    $email = $user->emailAddresses()->withoutGlobalScopes()
        ->where('email_address', 'alice@example.com')->first();
    expect($email)->not->toBeNull()
        ->and($email->verified_at)->toBeNull(); // id_token said false
});

it('rejects id_token signed with a key not in the JWKS', function (): void {
    $env = au61Env();
    au61EnableGoogle($env);
    $serverKp = au61MintRsaKeyPair();
    $attackerKp = au61MintRsaKeyPair();

    $idToken = au61MintIdToken($attackerKp, [
        'sub' => 'g-EVIL',
        'email' => 'eve@example.com',
        'email_verified' => true,
    ], 'https://accounts.google.com', 'goog-client-id');

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-1', 'id_token' => $idToken,
        ], 200),
        'www.googleapis.com/oauth2/v3/certs' => Http::response([
            'keys' => [$serverKp['jwk']],
        ], 200),
    ]);

    $client = Client::create(['environment_id' => $env->id]);
    $attempt = SignInAttempt::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'status' => SignInAttempt::STATUS_NEEDS_FIRST_FACTOR,
        'abandon_at' => now()->addMinutes(30),
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $attempt->getMorphClass(),
        'verifiable_id' => $attempt->id,
        'strategy' => 'oauth_google',
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addMinutes(10),
    ]);

    $state = StateToken::mint(
        environmentId: $env->id, providerKey: 'google', verificationId: $verification->id,
        clientId: $client->id, attemptId: $attempt->id, attemptKind: 'sign_in',
        redirectUrl: 'http://app.example.com/sign-in',
        redirectUrlComplete: 'http://app.example.com/dashboard',
        nonce: 'n-1',
    );

    $r = $this->withHeaders(['Host' => 'cb.authn.local'])
        ->withoutOpenApiAssertions()
        ->get('http://cb.authn.local/v1/oauth-callback/google?state='.urlencode($state).'&code=auth-code-1');

    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('__authn_error=oauth_id_token_invalid');
    expect(User::query()->withoutGlobalScopes()->where('environment_id', $env->id)->exists())->toBeFalse();
});
