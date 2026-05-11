<?php

declare(strict_types=1);

use App\Support\Base64Url;

it('round-trips arbitrary bytes through encode + decode', function (): void {
    foreach (['', "\x00", "\x00\x01\x02", random_bytes(32), random_bytes(65)] as $bytes) {
        $encoded = Base64Url::encode($bytes);
        expect(Base64Url::decode($encoded))->toBe($bytes);
        // No standard-base64 padding chars.
        expect($encoded)->not->toContain('=');
        // URL-safe alphabet only.
        expect($encoded)->not->toContain('+');
        expect($encoded)->not->toContain('/');
    }
});

it('encodes the canonical RFC 4648 §10 vectors', function (): void {
    expect(Base64Url::encode(''))->toBe('');
    expect(Base64Url::encode('f'))->toBe('Zg');
    expect(Base64Url::encode('fo'))->toBe('Zm8');
    expect(Base64Url::encode('foo'))->toBe('Zm9v');
    expect(Base64Url::encode('foobar'))->toBe('Zm9vYmFy');
});

it('decodes inputs with URL-safe substitutions', function (): void {
    expect(Base64Url::decode('-_'))->toBe(base64_decode('+/'));
});
