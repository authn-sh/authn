<?php

declare(strict_types=1);

use Tests\Feature\Http\Bapi\BapiTestSupport;


it('emits X-RateLimit-* headers on every BAPI response', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/users'));
    $r->assertOk()
        ->assertHeader('X-RateLimit-Limit')
        ->assertHeader('X-RateLimit-Remaining')
        ->assertHeader('X-RateLimit-Reset');
});

it('returns 429 + Retry-After once the bucket is exhausted', function (): void {
    $f = BapiTestSupport::bootEnv();
    // The /redirect_urls.create bucket is limit=60/60s; we'd rather not hit
    // 60 calls per test. Use the more aggressive /invitations.bulk bucket
    // (5/60s) instead.
    $body = ['invitations' => [['email_address' => 'bulk@example.com']]];

    for ($i = 0; $i < 5; $i++) {
        $this->withHeaders(BapiTestSupport::headers($f['token']))
            ->postJson(BapiTestSupport::url('/invitations/bulk'), $body)
            ->assertOk();
    }

    $sixth = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/invitations/bulk'), $body);
    $sixth->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit_exceeded')
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '5');
});
