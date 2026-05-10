<?php

declare(strict_types=1);

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\OauthProvider;
use App\Models\Session;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Http\SignIn\SignInTestSupport;

/**
 * AU-16: end-to-end OAuth sign-in / sign-up flows. Covers the full dance
 * (`POST /sign-ins → external_verification_redirect_url → callback → session`)
 * that the strategy + callback unit tests don't exercise together.
 */
function au16EnableProvider(Environment $env, string $key, bool $allowSignUp = true, bool $blockSubaddresses = false): OauthProvider
{
    $row = OauthProvider::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('provider_key', $key)
        ->firstOrFail();
    $row->forceFill([
        'enabled' => true,
        'allow_sign_in' => true,
        'allow_sign_up' => $allowSignUp,
        'block_email_subaddresses' => $blockSubaddresses,
        'client_id' => 'cid-'.$key,
        'encrypted_client_secret' => 'sec-'.$key,
    ])->save();

    return $row->refresh();
}

function au16SignInPost(array $f, array $body): TestResponse
{
    return test()->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', $body);
}

function au16StartOauth(array $f, string $providerKey, string $redirectUrl, string $redirectUrlComplete, ?string $identifierHint = 'oauth-hint@example.com'): array
{
    $cb = SignInTestSupport::clientWithCookie($f['env']);

    $created = test()->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->withUnencryptedCookie('__client', $cb['cookie'])
        ->withoutOpenApiAssertions()
        ->postJson('https://acme.authn.local/v1/client/sign-ins', $identifierHint !== null ? ['identifier' => $identifierHint] : []);
    $created->assertOk();
    $sid = $created->json('response.id');
    expect($sid)->toStartWith('sia_');

    $challenge = test()->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->withUnencryptedCookie('__client', $cb['cookie'])
        ->withoutOpenApiAssertions()
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", [
            'strategy' => 'oauth_'.$providerKey,
            'redirect_url' => $redirectUrl,
            'redirect_url_complete' => $redirectUrlComplete,
        ]);
    $challenge->assertOk();

    $url = $challenge->json('response.parent.first_factor_verification.external_verification_redirect_url')
        ?? $challenge->json('response.first_factor_verification.external_verification_redirect_url')
        ?? $challenge->json('response.external_verification_redirect_url');

    return ['sid' => $sid, 'authorize_url' => (string) $url, 'cookie' => $cb['cookie']];
}

function au16FollowCallback(array $f, string $authorizeUrl, ?array $userinfo = null): TestResponse
{
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $qs);
    $state = (string) ($qs['state'] ?? '');
    expect($state)->not->toBe('');

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600, 'scope' => 'openid email profile',
        ], 200),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response($userinfo ?? [
            'sub' => 'g-au16',
            'email' => 'alice@example.com',
            'email_verified' => true,
            'given_name' => 'Alice',
            'family_name' => 'Smith',
        ], 200),
    ]);

    return test()->withCredentials()
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->withoutOpenApiAssertions()
        ->get('https://acme.authn.local/v1/oauth-callback/google?state='.urlencode($state).'&code=ok');
}

it('OAuth sign-up: new user lands a session via Google preset', function (): void {
    $f = SignInTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    au16EnableProvider($f['env'], 'google');

    $oauth = au16StartOauth($f, 'google', 'http://app.example.com/sign-in', 'http://app.example.com/done');
    expect($oauth['authorize_url'])->toContain('https://accounts.google.com/o/oauth2/v2/auth');

    $callback = au16FollowCallback($f, $oauth['authorize_url']);
    $callback->assertRedirect();
    expect($callback->headers->get('Location'))->toBe('http://app.example.com/done');

    $user = User::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('first_name', 'Alice')
        ->firstOrFail();
    expect(ExternalAccount::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('user_id', $user->id)
        ->where('provider_user_id', 'g-au16')
        ->exists())->toBeTrue();
    expect(Session::query()->withoutGlobalScopes()
        ->where('user_id', $user->id)
        ->where('status', Session::STATUS_ACTIVE)
        ->exists())->toBeTrue();
});

it('OAuth sign-in: existing user with linked ExternalAccount completes without re-creating the user', function (): void {
    $f = SignInTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    $provider = au16EnableProvider($f['env'], 'google');

    $existing = User::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'first_name' => 'Existing',
    ]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $existing->id,
        'email_address' => 'existing@example.com',
        'verified_at' => now(),
        'is_primary' => true,
    ]);
    ExternalAccount::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $existing->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'g-existing',
        'encrypted_access_token' => 'old-at',
        'linked_at' => now(),
    ]);

    $oauth = au16StartOauth($f, 'google', 'http://app.example.com/sign-in', 'http://app.example.com/done');

    $callback = au16FollowCallback($f, $oauth['authorize_url'], [
        'sub' => 'g-existing',
        'email' => 'existing@example.com',
        'email_verified' => true,
    ]);
    $callback->assertRedirect();
    expect($callback->headers->get('Location'))->toBe('http://app.example.com/done');

    // No duplicate user, fresh session for the existing one.
    expect(User::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('first_name', 'Existing')
        ->count())->toBe(1);
    expect(Session::query()->withoutGlobalScopes()
        ->where('user_id', $existing->id)
        ->where('status', Session::STATUS_ACTIVE)
        ->exists())->toBeTrue();
    expect(ExternalAccount::query()->withoutGlobalScopes()
        ->where('user_id', $existing->id)
        ->first()
        ->encrypted_access_token)->toBe('at-1');
});

it('OAuth sign-up rejected: provider with allow_sign_up=false does not create a user', function (): void {
    $f = SignInTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    au16EnableProvider($f['env'], 'google', allowSignUp: false);

    $oauth = au16StartOauth($f, 'google', 'http://app.example.com/sign-in', 'http://app.example.com/done');

    $callback = au16FollowCallback($f, $oauth['authorize_url'], [
        'sub' => 'g-newcomer',
        'email' => 'newcomer@example.com',
        'email_verified' => true,
    ]);
    $callback->assertRedirect();
    expect($callback->headers->get('Location'))->toContain('__authn_error=sign_up_disabled');

    expect(User::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)->count())->toBe(0);
    expect(ExternalAccount::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)->count())->toBe(0);
});

it('OAuth callback flips the verification to verified when the dance succeeds', function (): void {
    $f = SignInTestSupport::bootEnv();
    app()->instance(Environment::class, $f['env']);
    au16EnableProvider($f['env'], 'google');

    $oauth = au16StartOauth($f, 'google', 'http://app.example.com/sign-in', 'http://app.example.com/done');
    au16FollowCallback($f, $oauth['authorize_url'])->assertRedirect();

    // Verification finished verified; ExternalAccount linked.
    $verification = Verification::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('strategy', 'oauth_google')
        ->latest('id')
        ->firstOrFail();
    expect($verification->status)->toBe(Verification::STATUS_VERIFIED);
});
