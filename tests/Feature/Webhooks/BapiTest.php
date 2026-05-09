<?php

declare(strict_types=1);

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Tests\Feature\Http\Bapi\BapiTestSupport;

it('POST /v1/webhooks/endpoints returns the signing_secret once', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/webhooks/endpoints'), [
            'url' => 'https://customer.example.com/hook',
            'enabled_event_types' => ['user.created', 'session.ended'],
        ]);
    $r->assertStatus(201);
    expect($r->json('signing_secret'))->toStartWith('whsec_');
    $id = $r->json('id');

    // GET responses must not include the secret.
    $get = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url("/webhooks/endpoints/{$id}"));
    $get->assertOk();
    expect($get->json('signing_secret'))->toBeNull();
    expect($get->json('signing_secret_prefix'))->toStartWith('whsec_');
});

it('POST /rotate-secret returns the new secret once and stashes the prior', function (): void {
    $f = BapiTestSupport::bootEnv();
    $created = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/webhooks/endpoints'), [
            'url' => 'https://customer.example.com/hook',
        ]);
    $id = $created->json('id');
    $oldDisplayed = $created->json('signing_secret');

    $rotate = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/webhooks/endpoints/{$id}/rotate-secret"));
    $rotate->assertOk();
    expect($rotate->json('signing_secret'))->toStartWith('whsec_')
        ->not->toBe($oldDisplayed);

    $endpoint = WebhookEndpoint::query()->withoutGlobalScopes()->where('id', $id)->first();
    expect($endpoint->prior_signing_secret)->not->toBeNull();
    expect($endpoint->prior_signing_secret_expires_at?->isFuture())->toBeTrue();

    // Subsequent GET still doesn't disclose the secret.
    $get = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url("/webhooks/endpoints/{$id}"));
    expect($get->json('signing_secret'))->toBeNull();
});

it('GET /v1/webhooks/deliveries lists the rows scoped to the env', function (): void {
    $f = BapiTestSupport::bootEnv();
    $created = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/webhooks/endpoints'), ['url' => 'https://customer.example.com/hook']);
    $endpointId = $created->json('id');

    WebhookEvent::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'type' => 'user.created',
        'data' => ['id' => 'user_a'],
    ]);
    $event = WebhookEvent::query()->withoutGlobalScopes()->latest('id')->first();
    WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpointId,
        'webhook_event_id' => $event->id,
        'status' => 'succeeded',
        'response_status' => 200,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/webhooks/deliveries'));
    $r->assertOk()->assertJsonPath('total_count', 1);
});
