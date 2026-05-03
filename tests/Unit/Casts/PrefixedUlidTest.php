<?php

declare(strict_types=1);

use App\Casts\PrefixedUlid;
use App\Support\Id;
use Illuminate\Database\Eloquent\Model;

/**
 * Throwaway model anchoring the cast against a real Eloquent class.
 */
class PrefixedUlidCastTestModel extends Model
{
    protected $guarded = [];
}

it('passes a valid id through on get', function (): void {
    $cast = new PrefixedUlid('user_');
    $id = Id::generate('user_');

    expect($cast->get(new PrefixedUlidCastTestModel, 'id', $id, []))->toBe($id);
});

it('passes a valid id through on set when the prefix matches', function (): void {
    $cast = new PrefixedUlid('user_');
    $id = Id::generate('user_');

    expect($cast->set(new PrefixedUlidCastTestModel, 'id', $id, []))->toBe($id);
});

it('returns null on null', function (): void {
    $cast = new PrefixedUlid('user_');

    expect($cast->get(new PrefixedUlidCastTestModel, 'id', null, []))->toBeNull();
    expect($cast->set(new PrefixedUlidCastTestModel, 'id', null, []))->toBeNull();
});

it('throws on get when the stored value is malformed', function (): void {
    $cast = new PrefixedUlid('user_');

    expect(fn () => $cast->get(new PrefixedUlidCastTestModel, 'id', 'not-an-id', []))
        ->toThrow(InvalidArgumentException::class);
});

it('throws on set when the prefix is wrong', function (): void {
    $cast = new PrefixedUlid('user_');
    $orgId = Id::generate('org_');

    expect(fn () => $cast->set(new PrefixedUlidCastTestModel, 'id', $orgId, []))
        ->toThrow(InvalidArgumentException::class, 'expects prefix "user_", got "org_"');
});

it('throws on set when the ULID portion is malformed', function (): void {
    $cast = new PrefixedUlid('user_');

    expect(fn () => $cast->set(new PrefixedUlidCastTestModel, 'id', 'user_short', []))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts any registered prefix when no scope is configured', function (): void {
    $cast = new PrefixedUlid;

    $userId = Id::generate('user_');
    $orgId = Id::generate('org_');

    expect($cast->set(new PrefixedUlidCastTestModel, 'id', $userId, []))->toBe($userId);
    expect($cast->set(new PrefixedUlidCastTestModel, 'id', $orgId, []))->toBe($orgId);
});

it('rejects an unregistered prefix even when no scope is configured', function (): void {
    $cast = new PrefixedUlid;

    expect(fn () => $cast->set(new PrefixedUlidCastTestModel, 'id', 'bogus_01HKX9SY9V7H7TF8C8K7J9X4ZB', []))
        ->toThrow(InvalidArgumentException::class, 'Unknown id prefix');
});
