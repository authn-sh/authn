<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\ExternalAccount;
use App\Models\OauthProvider;
use App\Models\Project;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Database\QueryException;

function makeEnvForOauth(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints a oauthp_ prefixed id and casts JSON columns to arrays', function (): void {
    $env = makeEnvForOauth();

    $provider = OauthProvider::create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_PRESET,
        'provider_key' => 'google',
        'name' => 'Google',
        'client_id' => 'gid',
        'encrypted_client_secret' => 'gsecret',
        'scopes' => ['openid', 'email'],
        'attribute_mapping' => ['email' => 'email_address'],
    ]);

    expect($provider->id)->toStartWith('oauthp_');
    expect($provider->scopes)->toBe(['openid', 'email']);
    expect($provider->attribute_mapping)->toBe(['email' => 'email_address']);

    $reloaded = $provider->fresh();
    expect($reloaded->enabled)->toBeTrue();
    expect($reloaded->allow_sign_in)->toBeTrue();
    expect($reloaded->allow_sign_up)->toBeTrue();
    expect($reloaded->block_email_subaddresses)->toBeFalse();
});

it('encrypts the client secret at rest and hides it from array serialization', function (): void {
    $env = makeEnvForOauth();

    $provider = OauthProvider::create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_PRESET,
        'provider_key' => 'github',
        'name' => 'GitHub',
        'client_id' => 'cid',
        'encrypted_client_secret' => 'super-secret-value',
    ]);

    expect($provider->encrypted_client_secret)->toBe('super-secret-value');

    $raw = (string) DB::table('oauth_providers')->where('id', $provider->id)->value('encrypted_client_secret');
    expect($raw)->not->toBe('super-secret-value');
    expect(strlen($raw))->toBeGreaterThan(20);

    expect(array_key_exists('encrypted_client_secret', $provider->toArray()))->toBeFalse();
});

it('enforces unique (environment_id, provider_key)', function (): void {
    $env = makeEnvForOauth();

    OauthProvider::create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_PRESET,
        'provider_key' => 'google',
        'name' => 'Google',
        'client_id' => 'a',
        'encrypted_client_secret' => 'b',
    ]);

    expect(fn () => OauthProvider::create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_PRESET,
        'provider_key' => 'google',
        'name' => 'Google',
        'client_id' => 'c',
        'encrypted_client_secret' => 'd',
    ]))->toThrow(QueryException::class);
});

it('computes the redirect_uri off the env FAPI host and provider_key', function (): void {
    config()->set('authn.app_host', 'authn.sh');
    config()->set('authn.app_scheme', 'https');
    config()->set('authn.routing_mode', 'subdomain');
    config()->set('authn.app_port_suffix', '');

    $env = makeEnvForOauth('acme');
    $provider = OauthProvider::create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_PRESET,
        'provider_key' => 'google',
        'name' => 'Google',
        'client_id' => 'gid',
        'encrypted_client_secret' => 'gsecret',
    ]);

    expect($provider->redirect_uri)->toBe('https://acme.authn.sh/v1/oauth-callback/google');
});

it('relates ExternalAccount → OauthProvider + User', function (): void {
    $env = makeEnvForOauth();
    $user = User::create(['environment_id' => $env->id]);

    $provider = OauthProvider::create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_PRESET,
        'provider_key' => 'google',
        'name' => 'Google',
        'client_id' => 'gid',
        'encrypted_client_secret' => 'gsecret',
    ]);

    $account = ExternalAccount::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => '1234567890',
        'email_address' => 'alice@example.com',
        'verified' => true,
        'scopes' => ['openid', 'email'],
        'encrypted_access_token' => 'at-token',
        'encrypted_refresh_token' => 'rt-token',
        'linked_at' => now(),
    ]);

    expect($account->id)->toStartWith('ext_');
    expect($account->oauthProvider->id)->toBe($provider->id);
    expect($account->user->id)->toBe($user->id);
    expect($user->refresh()->externalAccounts->pluck('id')->all())->toBe([$account->id]);
});

it('hides encrypted token columns and stores ciphertext on disk', function (): void {
    $env = makeEnvForOauth();
    $user = User::create(['environment_id' => $env->id]);
    $provider = OauthProvider::create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_PRESET,
        'provider_key' => 'google',
        'name' => 'Google',
        'client_id' => 'gid',
        'encrypted_client_secret' => 'gsecret',
    ]);

    $account = ExternalAccount::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'abc',
        'encrypted_access_token' => 'cleartext-access',
        'encrypted_refresh_token' => 'cleartext-refresh',
        'encrypted_id_token' => 'cleartext-id',
        'linked_at' => now(),
    ]);

    expect($account->encrypted_access_token)->toBe('cleartext-access');

    $arr = $account->toArray();
    expect(array_key_exists('encrypted_access_token', $arr))->toBeFalse();
    expect(array_key_exists('encrypted_refresh_token', $arr))->toBeFalse();
    expect(array_key_exists('encrypted_id_token', $arr))->toBeFalse();

    $rawAccess = (string) DB::table('external_accounts')->where('id', $account->id)->value('encrypted_access_token');
    expect($rawAccess)->not->toBe('cleartext-access');
});

it('enforces unique (environment_id, oauth_provider_id, provider_user_id)', function (): void {
    $env = makeEnvForOauth();
    $user = User::create(['environment_id' => $env->id]);
    $provider = OauthProvider::create([
        'environment_id' => $env->id,
        'provider_kind' => OauthProvider::KIND_PRESET,
        'provider_key' => 'google',
        'name' => 'Google',
        'client_id' => 'gid',
        'encrypted_client_secret' => 'gsecret',
    ]);

    ExternalAccount::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'pid',
        'encrypted_access_token' => 't',
        'linked_at' => now(),
    ]);

    expect(fn () => ExternalAccount::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'oauth_provider_id' => $provider->id,
        'provider_user_id' => 'pid',
        'encrypted_access_token' => 't2',
        'linked_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('extends Verification::strategies() with phone_code and accepts oauth_<key> patterns', function (): void {
    expect(Verification::strategies())->toContain(Verification::STRATEGY_PHONE_CODE);

    expect(Verification::isValidStrategy('phone_code'))->toBeTrue();
    expect(Verification::isValidStrategy('oauth_google'))->toBeTrue();
    expect(Verification::isValidStrategy('oauth_acme_corp'))->toBeTrue();
    expect(Verification::isValidStrategy('oauth_'))->toBeFalse();
    expect(Verification::isValidStrategy('OAUTH_google'))->toBeFalse();
    expect(Verification::isValidStrategy('not_a_strategy'))->toBeFalse();
});
