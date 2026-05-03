<?php

declare(strict_types=1);

use App\Concerns\HasPrefixedUlid;
use App\Support\Id;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Throwaway model exercising the trait against a real (in-memory) DB.
 */
class HasPrefixedUlidTestModel extends Model
{
    use HasPrefixedUlid;

    protected $table = 'has_prefixed_ulid_test';

    protected $guarded = [];

    public $timestamps = false;

    protected string $idPrefix = 'user_';
}

class HasPrefixedUlidNoPrefixModel extends Model
{
    use HasPrefixedUlid;

    protected $table = 'no_prefix_models';

    public $timestamps = false;
}

beforeEach(function (): void {
    Schema::create('has_prefixed_ulid_test', function ($table): void {
        $table->string('id', 64)->primary();
        $table->string('label')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('has_prefixed_ulid_test');
});

it('marks the primary key as a non-incrementing string', function (): void {
    $m = new HasPrefixedUlidTestModel;

    expect($m->getKeyType())->toBe('string');
    expect($m->getIncrementing())->toBeFalse();
});

it('auto-generates a prefixed id on creating when none is set', function (): void {
    $m = new HasPrefixedUlidTestModel(['label' => 'first']);
    $m->save();

    expect($m->getKey())->toStartWith('user_');
    expect(Id::isValid($m->getKey()))->toBeTrue();
});

it('preserves a manually-set id on save', function (): void {
    $manual = Id::generate('user_');
    $m = new HasPrefixedUlidTestModel(['id' => $manual, 'label' => 'manual']);
    $m->save();

    expect($m->getKey())->toBe($manual);
});

it('throws when a model omits the idPrefix property', function (): void {
    expect(fn () => new HasPrefixedUlidNoPrefixModel)
        ->toThrow(LogicException::class, 'must declare a non-empty `protected string $idPrefix`');
});

it('rejects setting the id with the wrong prefix via the cast', function (): void {
    $m = new HasPrefixedUlidTestModel;
    $orgId = Id::generate('org_');

    expect(fn () => $m->setAttribute('id', $orgId))
        ->toThrow(InvalidArgumentException::class, 'expects prefix "user_"');
});
