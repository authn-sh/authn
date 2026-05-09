<?php

declare(strict_types=1);

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;


function makeEnvForEmails(): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p']);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'env',
        'routing_label' => 'env',
    ]);
}

it('lowercases the email_address on save', function (): void {
    $env = makeEnvForEmails();
    $user = User::create(['environment_id' => $env->id]);

    $email = EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'Alice@EXAMPLE.com',
    ]);

    expect($email->email_address)->toBe('alice@example.com');
});

it('enforces unique (environment_id, email_address)', function (): void {
    $env = makeEnvForEmails();
    $user = User::create(['environment_id' => $env->id]);

    EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'a@example.com',
    ]);

    expect(fn () => EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'a@example.com',
    ]))->toThrow(QueryException::class);
});

it('promotes a primary email and demotes the previous primary via the observer', function (): void {
    $env = makeEnvForEmails();
    $user = User::create(['environment_id' => $env->id]);

    $first = EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'first@example.com',
        'is_primary' => true,
    ]);

    expect($user->refresh()->primary_email_address_id)->toBe($first->id);

    $second = EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'second@example.com',
        'is_primary' => true,
    ]);

    expect($first->refresh()->is_primary)->toBeFalse();
    expect($second->refresh()->is_primary)->toBeTrue();
    expect($user->refresh()->primary_email_address_id)->toBe($second->id);
});

it('exposes the primary email via the User relation', function (): void {
    $env = makeEnvForEmails();
    $user = User::create(['environment_id' => $env->id]);

    $email = EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'primary@example.com',
        'is_primary' => true,
    ]);

    expect($user->refresh()->primaryEmailAddress->id)->toBe($email->id);
});

it('isVerified() reflects verified_at presence', function (): void {
    $env = makeEnvForEmails();
    $user = User::create(['environment_id' => $env->id]);

    $email = EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'a@example.com',
    ]);
    expect($email->isVerified())->toBeFalse();

    $email->forceFill(['verified_at' => now()])->save();
    expect($email->isVerified())->toBeTrue();
});
