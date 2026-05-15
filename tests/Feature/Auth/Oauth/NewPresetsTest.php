<?php

declare(strict_types=1);

use App\Auth\Oauth\PresetRegistry;
use App\Auth\Oauth\Presets\DiscordPreset;
use App\Auth\Oauth\Presets\FacebookPreset;
use App\Auth\Oauth\Presets\GitLabPreset;
use App\Auth\Oauth\Presets\LinkedInPreset;
use App\Auth\Oauth\Presets\SlackPreset;
use App\Auth\Oauth\Presets\XPreset;
use App\Models\Environment;
use App\Models\OauthProvider;
use App\Models\Project;
use Tests\Support\OauthProviderFixtures;

function makeEnvForNewPresets(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('registers all six new presets on PresetRegistry', function (): void {
    $keys = app(PresetRegistry::class)->keys();

    expect($keys)->toContain('discord', 'facebook', 'linkedin', 'x', 'gitlab', 'slack');
});

it('hydrates the six new preset shapes via the fixture helper', function (): void {
    $env = makeEnvForNewPresets();

    foreach (['google', 'github', 'apple', 'microsoft', 'discord', 'facebook', 'linkedin', 'x', 'gitlab', 'slack'] as $key) {
        $row = OauthProviderFixtures::blankPreset($env, $key);
        expect($row->provider_key)->toBe($key);
        expect($row->enabled)->toBeFalse("preset {$key} should be disabled when blank");
        expect($row->client_id)->toBe('');
        expect($row->provider_kind)->toBe(OauthProvider::KIND_PRESET);
    }

    $count = OauthProvider::query()->withoutGlobalScopes()->where('environment_id', $env->id)->count();
    expect($count)->toBe(10);
});

it('Discord preset exposes OAuth2 endpoints + identify/email scopes', function (): void {
    $p = new DiscordPreset;

    expect($p->key())->toBe('discord');
    expect($p->name())->toBe('Discord');
    expect($p->authorizationEndpoint())->toBe('https://discord.com/oauth2/authorize');
    expect($p->tokenEndpoint())->toBe('https://discord.com/api/oauth2/token');
    expect($p->userinfoEndpoint())->toBe('https://discord.com/api/users/@me');
    expect($p->jwksUri())->toBeNull();
    expect($p->issuer())->toBeNull();
    expect($p->defaultScopes())->toBe(['identify', 'email']);
    expect($p->defaultAttributeMapping())->toBe([
        'provider_user_id' => 'id',
        'username' => 'username',
        'email_address' => 'email',
    ]);
    expect($p->idTokenSigningAlgs())->toBe([]);
});

it('Facebook preset exposes Graph v18.0 endpoints + public_profile/email scopes', function (): void {
    $p = new FacebookPreset;

    expect($p->key())->toBe('facebook');
    expect($p->authorizationEndpoint())->toBe('https://www.facebook.com/v18.0/dialog/oauth');
    expect($p->tokenEndpoint())->toBe('https://graph.facebook.com/v18.0/oauth/access_token');
    expect($p->userinfoEndpoint())->toBe('https://graph.facebook.com/v18.0/me');
    expect($p->defaultScopes())->toBe(['public_profile', 'email']);
    expect($p->defaultAttributeMapping())->toMatchArray([
        'email_address' => 'email',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
    ]);
});

it('LinkedIn preset exposes OIDC endpoints + standard OIDC scopes', function (): void {
    $p = new LinkedInPreset;

    expect($p->key())->toBe('linkedin');
    expect($p->issuer())->toBe('https://www.linkedin.com/oauth');
    expect($p->jwksUri())->toBe('https://www.linkedin.com/oauth/openid/jwks');
    expect($p->defaultScopes())->toBe(['openid', 'profile', 'email']);
    expect($p->defaultAttributeMapping())->toMatchArray([
        'provider_user_id' => 'sub',
        'email' => 'email',
        'first_name' => 'given_name',
        'last_name' => 'family_name',
    ]);
    expect($p->idTokenSigningAlgs())->toBe(['RS256']);
});

it('X preset exposes api.twitter.com endpoints + tweet.read/users.read scopes', function (): void {
    $p = new XPreset;

    expect($p->key())->toBe('x');
    expect($p->name())->toBe('X');
    expect($p->authorizationEndpoint())->toBe('https://twitter.com/i/oauth2/authorize');
    expect($p->tokenEndpoint())->toBe('https://api.twitter.com/2/oauth2/token');
    expect($p->userinfoEndpoint())->toBe('https://api.twitter.com/2/users/me');
    expect($p->defaultScopes())->toBe(['tweet.read', 'users.read']);
    expect($p->defaultAttributeMapping())->toBe([
        'provider_user_id' => 'id',
        'username' => 'username',
    ]);
    expect($p->idTokenSigningAlgs())->toBe([]);
});

it('GitLab preset exposes gitlab.com OIDC endpoints + standard scopes', function (): void {
    $p = new GitLabPreset;

    expect($p->key())->toBe('gitlab');
    expect($p->issuer())->toBe('https://gitlab.com');
    expect($p->authorizationEndpoint())->toBe('https://gitlab.com/oauth/authorize');
    expect($p->defaultScopes())->toBe(['openid', 'profile', 'email']);
    expect($p->defaultAttributeMapping())->toMatchArray([
        'provider_user_id' => 'sub',
        'username' => 'preferred_username',
    ]);
    expect($p->idTokenSigningAlgs())->toBe(['RS256']);
});

it('Slack preset exposes Sign-in-with-Slack OIDC endpoints', function (): void {
    $p = new SlackPreset;

    expect($p->key())->toBe('slack');
    expect($p->issuer())->toBe('https://slack.com');
    expect($p->authorizationEndpoint())->toBe('https://slack.com/openid/connect/authorize');
    expect($p->userinfoEndpoint())->toBe('https://slack.com/api/openid.connect.userInfo');
    expect($p->defaultScopes())->toBe(['openid', 'profile', 'email']);
    expect($p->idTokenSigningAlgs())->toBe(['RS256']);
});

it('every preset returns a non-empty key, name, and endpoint set', function (): void {
    foreach (app(PresetRegistry::class)->all() as $key => $preset) {
        expect($preset->key())->toBe($key);
        expect($preset->name())->not->toBe('');
        expect($preset->authorizationEndpoint())->toStartWith('https://');
        expect($preset->tokenEndpoint())->toStartWith('https://');
        expect($preset->userinfoEndpoint())->toStartWith('https://');
    }
});

it('computes the redirect_uri off the env FAPI host for a new-preset row', function (): void {
    config()->set('authn.app_host', 'authn.sh');
    config()->set('authn.app_scheme', 'https');
    config()->set('authn.routing_mode', 'subdomain');
    config()->set('authn.app_port_suffix', '');

    $env = makeEnvForNewPresets('acme');
    $row = OauthProviderFixtures::blankPreset($env->refresh(), 'discord');

    expect($row->redirect_uri)->toBe('https://acme.authn.sh/v1/oauth-callback/discord');
});
