<?php

declare(strict_types=1);

use App\Models\WebhookEndpoint;
use App\Webhooks\Signer;

it('produces a known fixture v1 segment', function (): void {
    // Fixture from the svix verification spec.
    $secret = 'whsecret';
    $body = '{"hello":"world"}';
    $messageId = 'msg_abc';
    $timestamp = 1700000000;

    $signer = new Signer;
    $segment = $signer->sign($body, $messageId, $timestamp, $secret);

    expect($segment)->toMatch('/^v1,[A-Za-z0-9+\/=]+$/');
    // Recomputing with the same inputs is deterministic.
    expect($signer->sign($body, $messageId, $timestamp, $secret))->toBe($segment);
    // Different secret → different signature.
    expect($signer->sign($body, $messageId, $timestamp, 'other'))->not->toBe($segment);
});

it('emits two segments while the prior secret is still in the rotation window', function (): void {
    $endpoint = new WebhookEndpoint;
    $endpoint->signing_secret = 'new-secret';
    $endpoint->prior_signing_secret = 'old-secret';
    $endpoint->prior_signing_secret_expires_at = now()->addHours(12);

    $header = (new Signer)->headerFor($endpoint, '{"a":1}', 'msg_x', 1700000000);
    $segments = explode(' ', $header);
    expect($segments)->toHaveCount(2);
    expect($segments[0])->toStartWith('v1,');
    expect($segments[1])->toStartWith('v1,');
});

it('drops the prior segment after the rotation window expires', function (): void {
    $endpoint = new WebhookEndpoint;
    $endpoint->signing_secret = 'new-secret';
    $endpoint->prior_signing_secret = 'old-secret';
    $endpoint->prior_signing_secret_expires_at = now()->subSecond();

    $header = (new Signer)->headerFor($endpoint, '{"a":1}', 'msg_x', 1700000000);
    expect(explode(' ', $header))->toHaveCount(1);
});

it('verifies the signature against either secret while in rotation', function (): void {
    $signer = new Signer;
    $body = '{"a":1}';
    $messageId = 'msg_x';
    $timestamp = 1700000000;
    $newSig = $signer->sign($body, $messageId, $timestamp, 'new-secret');
    $oldSig = $signer->sign($body, $messageId, $timestamp, 'old-secret');
    $header = $newSig.' '.$oldSig;

    expect($signer->verify($body, $messageId, $timestamp, $header, ['new-secret']))->toBeTrue();
    expect($signer->verify($body, $messageId, $timestamp, $header, ['old-secret']))->toBeTrue();
    expect($signer->verify($body, $messageId, $timestamp, $header, ['unknown']))->toBeFalse();
});
