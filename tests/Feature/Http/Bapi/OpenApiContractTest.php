<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use Tests\Feature\Http\Bapi\BapiTestSupport;

uses(RefreshDatabase::class);

/**
 * Loads the bundled OpenAPI 3.1 spec from the sibling `openapi/` repo and
 * validates one fixture response per BAPI resource family against its
 * `components.schemas.*` definition. If the spec hasn't been bundled (the
 * sibling repo isn't checked out, or `npm run build` hasn't run there), the
 * test is skipped — CI in this repo doesn't depend on the spec being
 * available, but the contract bite is in effect any time someone runs the
 * suite locally with the spec at hand.
 */
function stripExamples(mixed $node): void
{
    if (is_object($node)) {
        if (property_exists($node, 'examples')) {
            unset($node->examples);
        }
        foreach (get_object_vars($node) as $value) {
            stripExamples($value);
        }
    } elseif (is_array($node)) {
        foreach ($node as $value) {
            stripExamples($value);
        }
    }
}

function bundledOpenApiPath(): ?string
{
    foreach ([
        __DIR__.'/../../../../../openapi/dist/openapi.bundled.json',
        __DIR__.'/../../../../openapi/dist/openapi.bundled.json',
    ] as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function assertMatchesSchema(string $schemaName, mixed $data): void
{
    $path = bundledOpenApiPath();
    if ($path === null) {
        test()->markTestSkipped('OpenAPI bundle not found — run `npm run build` in the sibling openapi/ repo.');
    }
    $spec = json_decode((string) file_get_contents($path));
    if (! isset($spec->components->schemas->{$schemaName})) {
        test()->markTestSkipped("Schema {$schemaName} not in bundled spec.");
    }

    // OpenAPI 3.1 / JSON Schema 2020-12 use `examples[]`; justinrainbow
    // (draft-07) treats array values inside that key as URIs to resolve and
    // chokes when they aren't. Documentation-only — strip it before loading.
    stripExamples($spec);

    // Register the bundled spec under a synthetic URI so $ref pointers like
    // `#/components/schemas/Timestamp` resolve through the storage instead of
    // looping back into the inlined component tree.
    $storage = new SchemaStorage;
    $storage->addSchema('file://openapi.bundled.json', $spec);
    $rootSchema = (object) [
        '$ref' => 'file://openapi.bundled.json#/components/schemas/'.$schemaName,
    ];

    $validator = new Validator(new Factory($storage));
    $payload = json_decode(json_encode($data));
    $validator->validate($payload, $rootSchema, Constraint::CHECK_MODE_TYPE_CAST);

    if (! $validator->isValid()) {
        $errors = array_map(fn ($e) => "{$e['property']}: {$e['message']}", $validator->getErrors());
        test()->fail("Response did not match {$schemaName} schema:\n  - ".implode("\n  - ", $errors));
    }
    expect($validator->isValid())->toBeTrue();
}

it('user response matches OpenAPI components.schemas.User', function (): void {
    $f = BapiTestSupport::bootEnv();
    $created = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users'), [
            'first_name' => 'Alice',
            'email_addresses' => ['alice@example.com'],
            'password' => 'super-secret-password',
        ]);
    $created->assertStatus(201);

    assertMatchesSchema('User', $created->json());
});

it('session response matches OpenAPI components.schemas.Session', function (): void {
    $f = BapiTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    $client = Client::create(['environment_id' => $f['env']->id]);
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url("/sessions/{$session->id}"));
    $r->assertOk();
    assertMatchesSchema('Session', $r->json());
});

it('invitation response matches OpenAPI components.schemas.Invitation', function (): void {
    Bus::fake();
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/invitations'), ['email_address' => 'invited@example.com']);
    $r->assertStatus(201);
    assertMatchesSchema('Invitation', $r->json());
});

it('allowlist identifier response matches the schema', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/allowlist_identifiers'), ['identifier' => '@team.com']);
    $r->assertStatus(201);
    assertMatchesSchema('AllowlistIdentifier', $r->json());
});

it('blocklist identifier response matches the schema', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/blocklist_identifiers'), ['identifier' => 'bad@example.com']);
    $r->assertStatus(201);
    assertMatchesSchema('BlocklistIdentifier', $r->json());
});

it('redirect URL response matches the schema', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/redirect_urls'), ['url' => 'https://app.example.com/sso']);
    $r->assertStatus(201);
    assertMatchesSchema('RedirectUrl', $r->json());
});

it('instance settings response matches the schema', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/instance'));
    $r->assertOk();
    assertMatchesSchema('InstanceSettings', $r->json());
});
