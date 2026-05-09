<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Session;
use App\Models\User;
use App\Services\Sessions\SessionLifecycle;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

it('multi_session=false evicts the prior session when a fresh one is minted', function (): void {
    $f = SessionsTestSupport::bootEnv([
        'sessions' => ['multi_session' => false],
    ]);

    // Two users; one Client (browser) signs in as both — single-session mode
    // should evict the first session when the second is created.
    $client = Client::create(['environment_id' => $f['env']->id]);

    $userA = new User(['environment_id' => $f['env']->id]);
    $userA->setPassword('secret');
    $userA->save();
    EmailAddress::create([
        'environment_id' => $f['env']->id, 'user_id' => $userA->id,
        'email_address' => 'a@example.com', 'verified_at' => now(), 'is_primary' => true,
    ]);

    $userB = new User(['environment_id' => $f['env']->id]);
    $userB->setPassword('secret');
    $userB->save();
    EmailAddress::create([
        'environment_id' => $f['env']->id, 'user_id' => $userB->id,
        'email_address' => 'b@example.com', 'verified_at' => now(), 'is_primary' => true,
    ]);

    $sessionA = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'user_id' => $userA->id,
        'status' => Session::STATUS_ACTIVE,
    ]);

    // Simulate the new sign-in by minting session B and running enforcement.
    $sessionB = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'user_id' => $userB->id,
        'status' => Session::STATUS_ACTIVE,
    ]);
    app(SessionLifecycle::class)->enforceMultiSessionPolicy($f['env'], $client, $sessionB);

    expect(Session::query()->withoutGlobalScopes()->where('id', $sessionA->id)->first()->status)->toBe('replaced');
    expect(Session::query()->withoutGlobalScopes()->where('id', $sessionB->id)->first()->status)->toBe('active');
});

it('multi_session=true with cap=2 evicts the oldest live session on overflow', function (): void {
    $f = SessionsTestSupport::bootEnv([
        'sessions' => [
            'multi_session' => true,
            'max_concurrent_sessions_per_client' => 2,
        ],
    ]);
    $client = Client::create(['environment_id' => $f['env']->id]);

    // Three users with one session each on the same client. Activity ordering
    // (oldest → newest by last_active_at) determines who gets evicted.
    $sessions = [];
    foreach (['a', 'b', 'c'] as $i => $tag) {
        $user = new User(['environment_id' => $f['env']->id]);
        $user->setPassword('secret');
        $user->save();
        EmailAddress::create([
            'environment_id' => $f['env']->id, 'user_id' => $user->id,
            'email_address' => "{$tag}@example.com", 'verified_at' => now(), 'is_primary' => true,
        ]);
        $sessions[$tag] = Session::create([
            'environment_id' => $f['env']->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'status' => Session::STATUS_ACTIVE,
            'last_active_at' => now()->subMinutes(10 - $i * 4), // a oldest, c newest
        ]);
    }

    app(SessionLifecycle::class)->enforceMultiSessionPolicy($f['env'], $client, $sessions['c']);

    // The oldest session (a) is evicted; b and c remain.
    expect(Session::query()->withoutGlobalScopes()->where('id', $sessions['a']->id)->first()->status)->toBe('replaced');
    expect(Session::query()->withoutGlobalScopes()->where('id', $sessions['b']->id)->first()->status)->toBe('active');
    expect(Session::query()->withoutGlobalScopes()->where('id', $sessions['c']->id)->first()->status)->toBe('active');
});

it('lazy collapse on GET /v1/client when multi_session was toggled off', function (): void {
    $f = SessionsTestSupport::bootEnv([
        'sessions' => ['multi_session' => false],
    ]);
    $client = Client::create(['environment_id' => $f['env']->id]);

    foreach (['a', 'b'] as $i => $tag) {
        $user = new User(['environment_id' => $f['env']->id]);
        $user->setPassword('secret');
        $user->save();
        EmailAddress::create([
            'environment_id' => $f['env']->id, 'user_id' => $user->id,
            'email_address' => "{$tag}@example.com", 'verified_at' => now(), 'is_primary' => true,
        ]);
        Session::create([
            'environment_id' => $f['env']->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'status' => Session::STATUS_ACTIVE,
            'last_active_at' => now()->subMinutes(10 - $i * 4),
        ]);
    }

    app(SessionLifecycle::class)->enforceSingleSessionOnRead($f['env'], $client);

    $live = Session::query()
        ->withoutGlobalScopes()
        ->where('client_id', $client->id)
        ->whereIn('status', Session::LIVE_STATUSES)
        ->count();
    expect($live)->toBe(1);
});
