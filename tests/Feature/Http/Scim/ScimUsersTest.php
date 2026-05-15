<?php

declare(strict_types=1);

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ScimToken;
use App\Models\User;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

function bootScimEnv(): array
{
    config([
        'authn.routing_mode' => 'subdomain',
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
        'authn.bapi_host' => 'api.authn.local',
        'authn.dashboard_host' => 'dashboard.authn.local',
    ]);
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    Route::middleware('fapi')->domain('{env_slug}.authn.local')->group(base_path('routes/fapi.php'));

    $project = Project::create(['name' => 'P', 'slug' => 'p-'.uniqid()]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => ['https://app.example.com'],
    ]);
    (new SigningKeyGenerator)->generate($env);

    $creator = User::create(['environment_id' => $env->id]);

    return ['env' => $env, 'creator' => $creator];
}

function mintScimToken(Environment $env, User $creator, ?Organization $org = null): string
{
    $minted = ScimToken::mintPlaintext();
    ScimToken::create([
        'environment_id' => $env->id,
        'hashed_token' => $minted['hash'],
        'prefix' => $minted['prefix'],
        'organization_id' => $org?->id,
        'name' => 't',
        'created_by_user_id' => $creator->id,
    ]);

    return $minted['plaintext'];
}

it('rejects requests with no Authorization header', function (): void {
    $f = bootScimEnv();

    $r = $this->withHeaders(['Host' => 'acme.authn.local'])
        ->getJson('https://acme.authn.local/scim/v2/Users');

    $r->assertStatus(401)
        ->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:Error')
        ->assertJsonPath('status', '401');
});

it('rejects revoked tokens', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $token = ScimToken::query()->withoutGlobalScopes()->latest('id')->first();
    $token->revoke();

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->getJson('https://acme.authn.local/scim/v2/Users');

    $r->assertStatus(401);
});

it('returns a SCIM ListResponse for env-wide tokens', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);

    // Seed two users.
    foreach (['alice@acme.test', 'bob@acme.test'] as $email) {
        $u = User::create(['environment_id' => $f['env']->id]);
        EmailAddress::query()->withoutGlobalScopes()->create([
            'environment_id' => $f['env']->id,
            'user_id' => $u->id,
            'email_address' => $email,
            'verified_at' => now(),
            'is_primary' => true,
        ]);
    }

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->getJson('https://acme.authn.local/scim/v2/Users');

    $r->assertOk()
        ->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:ListResponse')
        ->assertJsonPath('totalResults', 3) // alice, bob + the creator User
        ->assertJsonPath('Resources.0.schemas.0', 'urn:ietf:params:scim:schemas:core:2.0:User');
});

it('filters by userName eq', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);

    foreach (['alice@acme.test', 'bob@acme.test'] as $email) {
        $u = User::create(['environment_id' => $f['env']->id]);
        EmailAddress::query()->withoutGlobalScopes()->create([
            'environment_id' => $f['env']->id,
            'user_id' => $u->id,
            'email_address' => $email,
            'verified_at' => now(),
            'is_primary' => true,
        ]);
    }

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->getJson('https://acme.authn.local/scim/v2/Users?filter='.urlencode('userName eq "alice@acme.test"'));

    $r->assertOk()
        ->assertJsonPath('totalResults', 1)
        ->assertJsonPath('Resources.0.userName', 'alice@acme.test');
});

it('respects startIndex + count pagination', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);

    for ($i = 0; $i < 5; $i++) {
        $u = User::create(['environment_id' => $f['env']->id]);
        EmailAddress::query()->withoutGlobalScopes()->create([
            'environment_id' => $f['env']->id,
            'user_id' => $u->id,
            'email_address' => "user{$i}@acme.test",
            'verified_at' => now(),
            'is_primary' => true,
        ]);
    }

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->getJson('https://acme.authn.local/scim/v2/Users?startIndex=1&count=2');
    $r->assertOk()->assertJsonPath('itemsPerPage', 2);

    $r2 = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->getJson('https://acme.authn.local/scim/v2/Users?startIndex=3&count=10');
    $r2->assertOk();
    expect($r2->json('itemsPerPage'))->toBeGreaterThan(0);
});

it('creates a new User from a SCIM POST', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->postJson('https://acme.authn.local/scim/v2/Users', [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'userName' => 'newhire@acme.test',
            'name' => ['givenName' => 'New', 'familyName' => 'Hire'],
            'emails' => [['value' => 'newhire@acme.test', 'primary' => true, 'type' => 'work']],
            'externalId' => 'ext-123',
            'active' => true,
        ]);

    $r->assertCreated()
        ->assertJsonPath('schemas.0', 'urn:ietf:params:scim:schemas:core:2.0:User')
        ->assertJsonPath('userName', 'newhire@acme.test')
        ->assertJsonPath('externalId', 'ext-123')
        ->assertJsonPath('active', true);
});

it('returns 409 when creating a SCIM user with an existing email', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $u = User::create(['environment_id' => $f['env']->id]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $u->id,
        'email_address' => 'existing@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->postJson('https://acme.authn.local/scim/v2/Users', [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'userName' => 'existing@acme.test',
            'emails' => [['value' => 'existing@acme.test', 'primary' => true]],
        ]);

    $r->assertStatus(409)
        ->assertJsonPath('scimType', 'uniqueness');
});

it('soft-deletes the User on DELETE', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $u = User::create(['environment_id' => $f['env']->id]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $u->id,
        'email_address' => 'target@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->deleteJson('https://acme.authn.local/scim/v2/Users/'.$u->id);

    $r->assertNoContent();
    $reloaded = User::withTrashed()->withoutGlobalScopes()->where('id', $u->id)->first();
    expect($reloaded?->deleted_at)->not->toBeNull();
});

it('PUT /Users/{id} replaces the user attributes (RFC 7644 §3.5.1)', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $u = User::create(['environment_id' => $f['env']->id, 'first_name' => 'Old', 'last_name' => 'Name', 'username' => 'old_user', 'external_id' => 'ext-old']);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $u->id,
        'email_address' => 'old@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->putJson('https://acme.authn.local/scim/v2/Users/'.$u->id, [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'userName' => 'fresh@acme.test',
            'name' => ['givenName' => 'Fresh', 'familyName' => 'User'],
            'emails' => [['value' => 'fresh@acme.test', 'primary' => true, 'type' => 'work']],
            'externalId' => 'ext-new',
            'active' => true,
        ]);

    $r->assertOk()
        ->assertJsonPath('userName', 'fresh@acme.test')
        ->assertJsonPath('name.givenName', 'Fresh')
        ->assertJsonPath('name.familyName', 'User')
        ->assertJsonPath('externalId', 'ext-new')
        ->assertJsonPath('active', true);

    $reloaded = User::query()->withoutGlobalScopes()->where('id', $u->id)->first();
    expect($reloaded->first_name)->toBe('Fresh');
    expect($reloaded->last_name)->toBe('User');
    expect($reloaded->external_id)->toBe('ext-new');
});

it('PUT /Users/{id} omitting active leaves banned untouched (PUT-as-replace surfaces null → false)', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $u = User::create(['environment_id' => $f['env']->id, 'banned' => true]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $u->id,
        'email_address' => 'banned@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    // Omit 'active' → mapper drops the key, controller leaves banned alone.
    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->putJson('https://acme.authn.local/scim/v2/Users/'.$u->id, [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'userName' => 'banned@acme.test',
            'name' => ['givenName' => 'Still', 'familyName' => 'Banned'],
            'emails' => [['value' => 'banned@acme.test', 'primary' => true]],
        ]);

    $r->assertOk()->assertJsonPath('active', false);
    expect(User::query()->withoutGlobalScopes()->where('id', $u->id)->first()->banned)->toBeTrue();
});

it('PATCH /Users/{id} replace active=false bans the user (deprovisioning play)', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $u = User::create(['environment_id' => $f['env']->id]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $u->id,
        'email_address' => 'deprov@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->patchJson('https://acme.authn.local/scim/v2/Users/'.$u->id, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
        ]);

    $r->assertOk()->assertJsonPath('active', false);
    expect(User::query()->withoutGlobalScopes()->where('id', $u->id)->first()->banned)->toBeTrue();
});

it('PATCH /Users/{id} replace active=true unbans the user', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $u = User::create(['environment_id' => $f['env']->id, 'banned' => true]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $u->id,
        'email_address' => 'reprov@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->patchJson('https://acme.authn.local/scim/v2/Users/'.$u->id, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => true]],
        ]);

    $r->assertOk()->assertJsonPath('active', true);
});

it('PATCH /Users/{id} replace on scalar paths updates the user', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $u = User::create(['environment_id' => $f['env']->id, 'first_name' => 'Old']);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $u->id,
        'email_address' => 'patch@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->patchJson('https://acme.authn.local/scim/v2/Users/'.$u->id, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [
                ['op' => 'replace', 'path' => 'name.givenName', 'value' => 'New'],
                ['op' => 'replace', 'path' => 'externalId', 'value' => 'ext-fresh'],
                ['op' => 'replace', 'path' => 'locale', 'value' => 'pt-BR'],
            ],
        ]);

    $r->assertOk();
    $reloaded = User::query()->withoutGlobalScopes()->where('id', $u->id)->first();
    expect($reloaded->first_name)->toBe('New');
    expect($reloaded->external_id)->toBe('ext-fresh');
    expect($reloaded->locale)->toBe('pt-BR');
});

it('PATCH /Users/{id} no-path replace applies a full resource replace', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $u = User::create(['environment_id' => $f['env']->id, 'first_name' => 'Before']);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $u->id,
        'email_address' => 'nopath@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->patchJson('https://acme.authn.local/scim/v2/Users/'.$u->id, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'value' => ['name' => ['givenName' => 'After']]]],
        ]);

    $r->assertOk();
    expect(User::query()->withoutGlobalScopes()->where('id', $u->id)->first()->first_name)->toBe('After');
});

it('PATCH /Users/{id} returns 400 when Operations is missing', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);
    $u = User::create(['environment_id' => $f['env']->id]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->patchJson('https://acme.authn.local/scim/v2/Users/'.$u->id, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
        ]);

    $r->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
});

it('PUT /Users/{id} returns 404 for a user outside the token scope', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->putJson('https://acme.authn.local/scim/v2/Users/usr_missing', [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'userName' => 'whoever@acme.test',
            'emails' => [['value' => 'whoever@acme.test', 'primary' => true]],
        ]);

    $r->assertStatus(404)->assertJsonPath('scimType', 'notFound');
});

it('projects only the requested attributes when attributes= is supplied', function (): void {
    $f = bootScimEnv();
    $plaintext = mintScimToken($f['env'], $f['creator']);

    $u = User::create(['environment_id' => $f['env']->id, 'first_name' => 'Alice', 'last_name' => 'Smith']);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $u->id,
        'email_address' => 'alice@acme.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withHeaders(['Host' => 'acme.authn.local', 'Authorization' => 'Bearer '.$plaintext])
        ->getJson('https://acme.authn.local/scim/v2/Users/'.$u->id.'?attributes=userName,name.givenName');

    $r->assertOk()
        ->assertJsonPath('userName', 'alice@acme.test')
        ->assertJsonPath('name.givenName', 'Alice');
    expect($r->json())->not->toHaveKey('emails');
});
