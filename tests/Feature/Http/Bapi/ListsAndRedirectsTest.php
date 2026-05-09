<?php

declare(strict_types=1);

use Tests\Feature\Http\Bapi\BapiTestSupport;

it('CRUDs allowlist identifiers', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/allowlist_identifiers'), ['identifier' => '@team.com', 'notify' => true]);
    $r->assertStatus(201)
        ->assertJsonPath('identifier', '@team.com')
        ->assertJsonPath('notify', true);
    $id = $r->json('id');

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/allowlist_identifiers'))
        ->assertOk()->assertJsonPath('total_count', 1);

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url("/allowlist_identifiers/{$id}"))
        ->assertOk()->assertJsonPath('deleted', true);
});

it('CRUDs blocklist identifiers', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/blocklist_identifiers'), ['identifier' => 'bad@example.com']);
    $r->assertStatus(201)->assertJsonPath('identifier', 'bad@example.com');
});

it('CRUDs redirect URLs', function (): void {
    $f = BapiTestSupport::bootEnv();
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/redirect_urls'), ['url' => 'https://app.example.com/sso']);
    $r->assertStatus(201)->assertJsonPath('url', 'https://app.example.com/sso');
    $id = $r->json('id');

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url("/redirect_urls/{$id}"))
        ->assertOk()->assertJsonPath('url', 'https://app.example.com/sso');

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url("/redirect_urls/{$id}"))
        ->assertOk()->assertJsonPath('deleted', true);
});
