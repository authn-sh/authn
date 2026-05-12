<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ScimAttributeMapping;
use App\Models\ScimToken;
use App\Models\User;
use App\Scim\DefaultAttributeMappings;

function makeEnvForScim(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints a plaintext scim_ token with a 32-byte body and a hash separable from the plaintext', function (): void {
    $minted = ScimToken::mintPlaintext();

    expect($minted['plaintext'])->toStartWith('scim_');
    expect(strlen($minted['plaintext']))->toBeGreaterThan(40); // scim_ + base64url(32)
    expect(strlen($minted['hash']))->toBe(32); // raw sha256
    expect($minted['prefix'])->toStartWith('scim_');
    expect(strlen($minted['prefix']))->toBe(12);
    expect(hash('sha256', $minted['plaintext'], true))->toBe($minted['hash']);
});

it('persists the hash + prefix and hides hashed_token from arrays', function (): void {
    $env = makeEnvForScim();
    $user = User::create(['environment_id' => $env->id]);

    $token = ScimToken::factory()->create([
        'environment_id' => $env->id,
        'created_by_user_id' => $user->id,
    ]);

    expect($token->id)->toStartWith('scimt_');
    expect($token->prefix)->toStartWith('scim_');
    expect(array_key_exists('hashed_token', $token->toArray()))->toBeFalse();
});

it('verifies a freshly-minted plaintext and rejects revoked/expired/unknown tokens', function (): void {
    $env = makeEnvForScim();
    app()->instance(Environment::class, $env);
    $user = User::create(['environment_id' => $env->id]);

    $minted = ScimToken::mintPlaintext();
    $row = ScimToken::create([
        'environment_id' => $env->id,
        'hashed_token' => $minted['hash'],
        'prefix' => $minted['prefix'],
        'name' => 't',
        'created_by_user_id' => $user->id,
    ]);

    expect(ScimToken::verify($minted['plaintext'])?->id)->toBe($row->id);
    expect(ScimToken::verify('scim_unknown-value-x'))->toBeNull();
    expect(ScimToken::verify('not-a-scim-token'))->toBeNull();

    $row->revoke();
    expect(ScimToken::verify($minted['plaintext']))->toBeNull();
    expect($row->fresh()->revoked_at)->not->toBeNull();
    app()->forgetInstance(Environment::class);
});

it('treats expires_at in the past as unusable', function (): void {
    $env = makeEnvForScim();
    app()->instance(Environment::class, $env);
    $user = User::create(['environment_id' => $env->id]);

    $minted = ScimToken::mintPlaintext();
    ScimToken::create([
        'environment_id' => $env->id,
        'hashed_token' => $minted['hash'],
        'prefix' => $minted['prefix'],
        'name' => 't',
        'created_by_user_id' => $user->id,
        'expires_at' => now()->subDay(),
    ]);

    expect(ScimToken::verify($minted['plaintext']))->toBeNull();
    app()->forgetInstance(Environment::class);
});

it('active() scope returns only non-revoked, non-expired rows', function (): void {
    $env = makeEnvForScim();
    $user = User::create(['environment_id' => $env->id]);

    $active = ScimToken::factory()->create([
        'environment_id' => $env->id,
        'created_by_user_id' => $user->id,
    ]);
    ScimToken::factory()->revoked()->create([
        'environment_id' => $env->id,
        'created_by_user_id' => $user->id,
    ]);
    ScimToken::factory()->expired()->create([
        'environment_id' => $env->id,
        'created_by_user_id' => $user->id,
    ]);

    expect(ScimToken::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

it('resolves SCIM mappings as defaults merged with per-org overrides', function (): void {
    $env = makeEnvForScim();
    $user = User::create(['environment_id' => $env->id]);
    $org = Organization::create([
        'environment_id' => $env->id,
        'name' => 'Acme',
        'slug' => 'acme',
    ]);

    ScimAttributeMapping::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'source_attribute' => 'userName',
        'target_attribute' => 'username',
        'transform' => 'lower(value)',
    ]);
    ScimAttributeMapping::factory()->create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'source_attribute' => 'custom.dept',
        'target_attribute' => 'public_metadata.dept',
    ]);

    $resolved = ScimAttributeMapping::resolveFor($org);

    expect($resolved['userName'])->toBe(['target' => 'username', 'transform' => 'lower(value)']);
    expect($resolved['custom.dept'])->toBe(['target' => 'public_metadata.dept', 'transform' => null]);
    expect($resolved['name.givenName'])->toBe([
        'target' => DefaultAttributeMappings::DEFAULTS['name.givenName'],
        'transform' => null,
    ]);
});
