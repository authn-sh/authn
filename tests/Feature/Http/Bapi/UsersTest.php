<?php

declare(strict_types=1);

use App\Models\EmailAddress;
use App\Models\User;
use Tests\Feature\Http\Bapi\BapiTestSupport;


it('lists, creates, reads, updates, deletes a user', function (): void {
    $f = BapiTestSupport::bootEnv();

    // Create
    $created = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users'), [
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email_addresses' => ['alice@example.com'],
            'password' => 'super-secret-password',
        ]);
    $created->assertStatus(201)->assertJsonPath('first_name', 'Alice');
    $userId = $created->json('id');

    // Read
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url("/users/{$userId}"))
        ->assertOk()
        ->assertJsonPath('id', $userId);

    // Update
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url("/users/{$userId}"), ['first_name' => 'Alicia'])
        ->assertOk()->assertJsonPath('first_name', 'Alicia');

    // List
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/users?limit=5'))
        ->assertOk()->assertJsonPath('total_count', 1);

    // Delete
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url("/users/{$userId}"))
        ->assertOk()->assertJsonPath('deleted', true);
    expect(User::query()->withoutGlobalScopes()->where('id', $userId)->first()?->trashed())->toBeTrue();
});

it('imports a pre-hashed password via password_digest + password_hasher', function (): void {
    $f = BapiTestSupport::bootEnv();

    $hash = password_hash('imported-password', PASSWORD_BCRYPT);
    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users'), [
            'email_addresses' => ['migrated@example.com'],
            'password_digest' => $hash,
            'password_hasher' => 'bcrypt',
        ]);
    $r->assertStatus(201);
    $user = User::query()->withoutGlobalScopes()->where('id', $r->json('id'))->first();
    expect($user->password_imported)->toBeTrue();
    expect($user->password_hasher)->toBe('bcrypt');
    expect($user->checkPassword('imported-password'))->toBeTrue();
});

it('honours Idempotency-Key on create — replay returns cached row, no DB write', function (): void {
    $f = BapiTestSupport::bootEnv();
    $key = 'replay-test-1';
    $body = [
        'email_addresses' => ['idem@example.com'],
        'password' => 'super-secret-password',
    ];

    $first = $this->withHeaders(BapiTestSupport::headers($f['token'], ['Idempotency-Key' => $key]))
        ->postJson(BapiTestSupport::url('/users'), $body);
    $first->assertStatus(201);

    $second = $this->withHeaders(BapiTestSupport::headers($f['token'], ['Idempotency-Key' => $key]))
        ->postJson(BapiTestSupport::url('/users'), $body);
    $second->assertStatus(201)->assertJsonPath('id', $first->json('id'));

    expect(User::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('rejects an Idempotency-Key replay with a different body', function (): void {
    $f = BapiTestSupport::bootEnv();
    $key = 'replay-test-2';

    $this->withHeaders(BapiTestSupport::headers($f['token'], ['Idempotency-Key' => $key]))
        ->postJson(BapiTestSupport::url('/users'), [
            'email_addresses' => ['mismatch-a@example.com'],
            'password' => 'super-secret-password',
        ])->assertStatus(201);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token'], ['Idempotency-Key' => $key]))
        ->postJson(BapiTestSupport::url('/users'), [
            'email_addresses' => ['mismatch-b@example.com'],
            'password' => 'super-secret-password',
        ]);
    $r->assertStatus(422)->assertJsonPath('errors.0.code', 'idempotency_key_in_use_with_different_request');
});

it('cross-env isolation: env A token cannot read env B users', function (): void {
    // Start two envs with different tokens.
    $a = BapiTestSupport::bootEnv('aco');
    $b = BapiTestSupport::bootEnv('bco');
    BapiTestSupport::reloadRoutes();

    // Plant a user under env A.
    $userA = new User(['environment_id' => $a['env']->id, 'first_name' => 'A']);
    $userA->save();
    EmailAddress::create([
        'environment_id' => $a['env']->id, 'user_id' => $userA->id,
        'email_address' => 'env-a@example.com', 'verified_at' => now(), 'is_primary' => true,
    ]);

    // Env B's token cannot see env A's user.
    $this->withHeaders(BapiTestSupport::headers($b['token']))
        ->getJson(BapiTestSupport::url("/users/{$userA->id}"))
        ->assertStatus(404);

    // Env A's token sees its user.
    $this->withHeaders(BapiTestSupport::headers($a['token']))
        ->getJson(BapiTestSupport::url("/users/{$userA->id}"))
        ->assertOk();
});

it('verify_password returns the right boolean', function (): void {
    $f = BapiTestSupport::bootEnv();
    $created = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users'), [
            'email_addresses' => ['vp@example.com'],
            'password' => 'right-password-here',
        ]);
    $userId = $created->json('id');

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/users/{$userId}/verify_password"), ['password' => 'right-password-here'])
        ->assertOk()->assertJsonPath('verified', true);

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/users/{$userId}/verify_password"), ['password' => 'nope'])
        ->assertOk()->assertJsonPath('verified', false);
});

it('ban / unban / lock / unlock toggle the flags', function (): void {
    $f = BapiTestSupport::bootEnv();
    $created = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/users'), [
            'email_addresses' => ['flags@example.com'],
            'password' => 'super-secret-password',
        ]);
    $userId = $created->json('id');

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/users/{$userId}/ban"))->assertOk()->assertJsonPath('banned', true);
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/users/{$userId}/unban"))->assertOk()->assertJsonPath('banned', false);
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/users/{$userId}/lock"), ['lockout_minutes' => 5])->assertOk()->assertJsonPath('locked', true);
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/users/{$userId}/unlock"))->assertOk()->assertJsonPath('locked', false);
});

it('list with filters: by query, banned flag, sort by created_at', function (): void {
    $f = BapiTestSupport::bootEnv();
    foreach (['Alice', 'Bob', 'Charlie'] as $i => $name) {
        $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
            ->postJson(BapiTestSupport::url('/users'), [
                'first_name' => $name,
                'email_addresses' => [strtolower($name).'@example.com'],
                'password' => 'super-secret-password',
            ]);
        if ($name === 'Bob') {
            $this->withHeaders(BapiTestSupport::headers($f['token']))
                ->postJson(BapiTestSupport::url("/users/{$r->json('id')}/ban"));
        }
    }

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/users?banned=1'))
        ->assertOk()->assertJsonPath('total_count', 1);
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/users?query=ali'))
        ->assertOk()->assertJsonPath('total_count', 1);
});
