<?php

declare(strict_types=1);

use App\Support\Id;

it('generates a 30-character prefixed ULID', function (): void {
    $id = Id::generate('user_');

    expect($id)->toStartWith('user_')->and(strlen($id))->toBe(31);
    expect(Id::isValid($id))->toBeTrue();
});

it('round-trips a generated id through parse', function (): void {
    $id = Id::generate('org_');
    ['prefix' => $prefix, 'ulid' => $ulid] = Id::parse($id);

    expect($prefix)->toBe('org_');
    expect(strlen($ulid))->toBe(26);
    expect($ulid)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('generates monotonically distinct ids for the same prefix', function (): void {
    $ids = collect(range(1, 100))->map(fn () => Id::generate('sess_'));

    expect($ids->unique())->toHaveCount(100);
});

it('rejects unknown prefixes on generate', function (): void {
    expect(fn () => Id::generate('bogus_'))
        ->toThrow(InvalidArgumentException::class, 'Unknown id prefix: bogus_');
});

it('rejects unknown prefixes on parse', function (): void {
    expect(fn () => Id::parse('bogus_01HKX9SY9V7H7TF8C8K7J9X4ZB'))
        ->toThrow(InvalidArgumentException::class, 'Unknown id prefix: bogus_');
});

it('rejects ids without an underscore', function (): void {
    expect(fn () => Id::parse('userwithoutunderscore'))
        ->toThrow(InvalidArgumentException::class, 'Malformed id (no prefix)');
});

it('rejects ids with a malformed ULID portion', function (): void {
    expect(fn () => Id::parse('user_short'))
        ->toThrow(InvalidArgumentException::class, 'Malformed ULID portion');
});

it('isValid returns false instead of throwing', function (): void {
    expect(Id::isValid('not-an-id'))->toBeFalse();
    expect(Id::isValid('bogus_01HKX9SY9V7H7TF8C8K7J9X4ZB'))->toBeFalse();
    expect(Id::isValid(Id::generate('user_')))->toBeTrue();
});
