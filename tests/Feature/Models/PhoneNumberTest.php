<?php

declare(strict_types=1);

use App\Http\Resources\UserResource;
use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;

function makeEnvForPhones(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints a phn_ prefixed id and stores E.164 format', function (): void {
    $env = makeEnvForPhones();
    $user = User::create(['environment_id' => $env->id]);

    $phone = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550100',
    ]);

    expect($phone->id)->toStartWith('phn_');
    expect($phone->phone_number)->toBe('+15555550100');
    expect($phone->isVerified())->toBeFalse();
});

it('enforces unique (environment_id, phone_number)', function (): void {
    $env = makeEnvForPhones();
    $user = User::create(['environment_id' => $env->id]);

    PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550101',
    ]);

    expect(fn () => PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550101',
    ]))->toThrow(QueryException::class);
});

it('promotes a primary phone and demotes the previous primary via the observer', function (): void {
    $env = makeEnvForPhones();
    $user = User::create(['environment_id' => $env->id]);

    $first = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550110',
        'is_primary' => true,
    ]);

    expect($user->refresh()->primary_phone_number_id)->toBe($first->id);

    $second = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550111',
        'is_primary' => true,
    ]);

    expect($first->refresh()->is_primary)->toBeFalse();
    expect($second->refresh()->is_primary)->toBeTrue();
    expect($user->refresh()->primary_phone_number_id)->toBe($second->id);
});

it('keeps default_second_factor mutually exclusive across a user', function (): void {
    $env = makeEnvForPhones();
    $user = User::create(['environment_id' => $env->id]);

    $first = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550120',
        'default_second_factor' => true,
    ]);

    $second = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550121',
        'default_second_factor' => true,
    ]);

    expect($first->refresh()->default_second_factor)->toBeFalse();
    expect($second->refresh()->default_second_factor)->toBeTrue();
});

it('markVerified() flips verified_at and toggles user.phone_number_enabled', function (): void {
    $env = makeEnvForPhones();
    $user = User::create(['environment_id' => $env->id]);

    $phone = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550130',
    ]);

    expect($user->refresh()->phone_number_enabled)->toBeFalse();

    $phone->markVerified();

    expect($phone->refresh()->verified_at)->not->toBeNull();
    expect($user->refresh()->phone_number_enabled)->toBeTrue();
});

it('exposes phoneNumbers + primaryPhoneNumber on User', function (): void {
    $env = makeEnvForPhones();
    $user = User::create(['environment_id' => $env->id]);

    $phone = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550140',
        'is_primary' => true,
    ]);

    expect($user->refresh()->phoneNumbers->pluck('id')->all())->toBe([$phone->id]);
    expect($user->refresh()->primaryPhoneNumber->id)->toBe($phone->id);
});

it('serialises phone_numbers on UserResource with the flat shape', function (): void {
    $env = makeEnvForPhones();
    $user = User::create(['environment_id' => $env->id]);

    $phone = PhoneNumber::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550150',
        'is_primary' => true,
        'reserved_for_second_factor' => true,
    ]);
    $phone->markVerified();

    $payload = UserResource::from($user->refresh());

    expect($payload['primary_phone_number_id'])->toBe($phone->id);
    expect($user->refresh()->phone_number_enabled)->toBeTrue();
    expect($payload['phone_numbers'])->toHaveCount(1);
    $entry = $payload['phone_numbers'][0];
    expect($entry['object'])->toBe('phone_number');
    expect($entry['id'])->toBe($phone->id);
    expect($entry['phone_number'])->toBe('+15555550150');
    expect($entry['verified'])->toBeTrue();
    expect($entry['is_primary'])->toBeTrue();
    expect($entry['reserved_for_second_factor'])->toBeTrue();
    expect($entry['default_second_factor'])->toBeFalse();
    expect($entry)->toHaveKey('current_challenge_id');
    expect($entry['linked_to'])->toBe([]);
});
