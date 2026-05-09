<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationCreated;
use App\Events\Organizations\OrganizationDeleted;
use App\Events\Organizations\OrganizationUpdated;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Http\Bapi\BapiTestSupport;

uses(RefreshDatabase::class);

function makeUser(Environment $env, string $username): User
{
    $u = new User(['environment_id' => $env->id, 'username' => $username]);
    $u->save();

    return $u;
}

it('POST /v1/organizations creates an org, fires OrganizationCreated, returns the resource', function (): void {
    Event::fake([OrganizationCreated::class]);
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/organizations'), [
            'name' => 'Acme Inc',
            'slug' => 'acme',
            'public_metadata' => ['plan' => 'pro'],
            'max_allowed_memberships' => 10,
        ]);
    $r->assertStatus(201)
        ->assertJsonPath('object', 'organization')
        ->assertJsonPath('name', 'Acme Inc')
        ->assertJsonPath('slug', 'acme')
        ->assertJsonPath('members_count', 0)
        ->assertJsonPath('public_metadata.plan', 'pro')
        ->assertJsonPath('max_allowed_memberships', 10);
    expect($r->json('id'))->toStartWith('org_');
    Event::assertDispatched(OrganizationCreated::class, 1);
});

it('POST auto-slugifies the name when no slug is supplied', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/organizations'), ['name' => 'Hello World']);
    $r->assertStatus(201)->assertJsonPath('slug', 'hello-world');
});

it('POST 409s on duplicate slug within the same env', function (): void {
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);
    $this->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'A', 'slug' => 'dup'])->assertStatus(201);
    $this->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'B', 'slug' => 'dup'])->assertStatus(409);
});

it('POST 422s when created_by user does not exist in this env', function (): void {
    $f = BapiTestSupport::bootEnv();
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/organizations'), [
            'name' => 'A',
            'created_by' => 'user_doesnotexist',
        ])->assertStatus(422);
});

it('POST 422s on a malformed slug', function (): void {
    $f = BapiTestSupport::bootEnv();
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/organizations'), ['name' => 'A', 'slug' => 'BAD SLUG'])->assertStatus(422);
});

it('GET /v1/organizations returns 401 without a key', function (): void {
    $f = BapiTestSupport::bootEnv();
    $this->withHeaders(['Host' => 'api.authn.local', 'Accept' => 'application/json'])
        ->getJson(BapiTestSupport::url('/organizations'))->assertStatus(401);
});

it('GET /v1/organizations only returns rows from the bound env', function (): void {
    $a = BapiTestSupport::bootEnv('aaa');
    $headersA = BapiTestSupport::headers($a['token']);
    $this->withHeaders($headersA)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'OnlyA', 'slug' => 'only-a'])->assertStatus(201);

    $b = BapiTestSupport::bootEnv('bbb');
    $headersB = BapiTestSupport::headers($b['token']);
    $this->withHeaders($headersB)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'OnlyB', 'slug' => 'only-b'])->assertStatus(201);

    $r = $this->withHeaders($headersB)->getJson(BapiTestSupport::url('/organizations'));
    $r->assertOk()->assertJsonPath('total_count', 1)->assertJsonPath('data.0.slug', 'only-b');
});

it('GET supports query and pagination', function (): void {
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);
    foreach (['Alpha', 'Beta', 'Aleph'] as $name) {
        $this->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => $name]);
    }
    $r = $this->withHeaders($headers)->getJson(BapiTestSupport::url('/organizations?query=al&limit=10'));
    $r->assertOk();
    expect($r->json('total_count'))->toBe(2);
});

it('GET /v1/organizations/{id} returns 404 across env boundaries', function (): void {
    $a = BapiTestSupport::bootEnv('aaa');
    $r = $this->withHeaders(BapiTestSupport::headers($a['token']))
        ->postJson(BapiTestSupport::url('/organizations'), ['name' => 'Only A', 'slug' => 'only-a']);
    $orgId = $r->json('id');

    $b = BapiTestSupport::bootEnv('bbb');
    $this->withHeaders(BapiTestSupport::headers($b['token']))
        ->getJson(BapiTestSupport::url("/organizations/{$orgId}"))->assertStatus(404);
});

it('PATCH /v1/organizations/{id} updates fields and fires OrganizationUpdated', function (): void {
    Event::fake([OrganizationUpdated::class]);
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);
    $r = $this->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'Old']);
    $id = $r->json('id');

    $r2 = $this->withHeaders($headers)->patchJson(BapiTestSupport::url("/organizations/{$id}"), [
        'name' => 'New',
        'public_metadata' => ['k' => 'v'],
    ]);
    $r2->assertOk()->assertJsonPath('name', 'New')->assertJsonPath('public_metadata.k', 'v');
    Event::assertDispatched(OrganizationUpdated::class, 1);
});

it('PATCH 409s when renaming to an existing slug', function (): void {
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);
    $this->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'A', 'slug' => 'a'])->assertStatus(201);
    $r = $this->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'B', 'slug' => 'b']);
    $id = $r->json('id');

    $this->withHeaders($headers)->patchJson(BapiTestSupport::url("/organizations/{$id}"), ['slug' => 'a'])->assertStatus(409);
});

it('DELETE /v1/organizations/{id} cascades children and fires OrganizationDeleted', function (): void {
    Event::fake([OrganizationDeleted::class]);
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);
    $r = $this->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), ['name' => 'A']);
    $id = $r->json('id');

    $r2 = $this->withHeaders($headers)->deleteJson(BapiTestSupport::url("/organizations/{$id}"));
    $r2->assertOk()->assertJsonPath('deleted', true);
    expect(Organization::query()->withoutGlobalScopes()->where('id', $id)->exists())->toBeFalse();
    Event::assertDispatched(OrganizationDeleted::class, 1);
});

it('DELETE 422s when admin_delete_enabled is false', function (): void {
    $f = BapiTestSupport::bootEnv();
    $headers = BapiTestSupport::headers($f['token']);
    $r = $this->withHeaders($headers)->postJson(BapiTestSupport::url('/organizations'), [
        'name' => 'No-Delete',
        'admin_delete_enabled' => false,
    ]);
    $id = $r->json('id');
    $this->withHeaders($headers)->deleteJson(BapiTestSupport::url("/organizations/{$id}"))->assertStatus(422);
});
