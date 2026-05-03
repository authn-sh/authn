<?php

declare(strict_types=1);

use App\Services\Verification\CodeGenerator;

it('produces a 6-digit zero-padded numeric code by default', function (): void {
    $codes = collect(range(1, 50))->map(fn () => (new CodeGenerator)->generateNumericCode());

    foreach ($codes as $c) {
        expect($c)->toMatch('/^[0-9]{6}$/');
    }
});

it('honors a custom length within the [4, 12] band', function (): void {
    $g = new CodeGenerator;

    expect($g->generateNumericCode(4))->toMatch('/^[0-9]{4}$/');
    expect($g->generateNumericCode(8))->toMatch('/^[0-9]{8}$/');
    expect($g->generateNumericCode(12))->toMatch('/^[0-9]{12}$/');
});

it('rejects lengths outside [4, 12]', function (): void {
    $g = new CodeGenerator;

    expect(fn () => $g->generateNumericCode(3))->toThrow(InvalidArgumentException::class);
    expect(fn () => $g->generateNumericCode(13))->toThrow(InvalidArgumentException::class);
});

it('produces a 43-character magic-link token', function (): void {
    $token = (new CodeGenerator)->generateMagicLinkToken();

    expect(strlen($token))->toBe(43);
});

it('hashes a value with sha256', function (): void {
    $h = (new CodeGenerator)->hash('hello');

    expect($h)->toBe(hash('sha256', 'hello'));
    expect($h)->toMatch('/^[0-9a-f]{64}$/');
});
