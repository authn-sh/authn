<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Session;
use App\Models\SessionActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sessionFixture(): array
{
    $project = Project::create(['name' => 'P', 'slug' => 'p']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'env',
        'frontend_api_host' => 'env.authn.local',
    ]);
    $client = Client::create(['environment_id' => $env->id]);
    $user = User::create(['environment_id' => $env->id]);

    return ['env' => $env, 'client' => $client, 'user' => $user];
}

it('defaults status to active and expire_at to ~7 days on create', function (): void {
    $f = sessionFixture();
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'user_id' => $f['user']->id,
    ]);

    expect($session->id)->toStartWith('sess_');
    expect($session->status)->toBe('active');
    expect($session->expire_at->getTimestamp())->toBeGreaterThan(now()->addDays(6)->getTimestamp());
    expect($session->expire_at->getTimestamp())->toBeLessThanOrEqual(now()->addDays(7)->getTimestamp() + 1);
    expect($session->last_active_at)->not->toBeNull();
});

it('sets abandon_at when created in pending status', function (): void {
    $f = sessionFixture();
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'user_id' => $f['user']->id,
        'status' => Session::STATUS_PENDING,
    ]);

    expect($session->abandon_at)->not->toBeNull();
    expect($session->abandon_at->getTimestamp())->toBeGreaterThan(now()->addHours(23)->getTimestamp());
});

it('allows pending → active and active → ended transitions', function (): void {
    $f = sessionFixture();
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'user_id' => $f['user']->id,
        'status' => Session::STATUS_PENDING,
    ]);

    $session->status = Session::STATUS_ACTIVE;
    $session->save();
    expect($session->fresh()->status)->toBe('active');

    $session->status = Session::STATUS_ENDED;
    $session->save();
    expect($session->fresh()->status)->toBe('ended');
});

it('forbids transitions out of any terminal status', function (): void {
    $f = sessionFixture();
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'user_id' => $f['user']->id,
    ]);
    $session->status = Session::STATUS_ENDED;
    $session->save();

    $session->status = Session::STATUS_ACTIVE;
    expect(fn () => $session->save())
        ->toThrow(InvalidArgumentException::class, 'Illegal Session transition');
});

it('isLive() reflects pending or active status', function (): void {
    $f = sessionFixture();
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'user_id' => $f['user']->id,
    ]);
    expect($session->isLive())->toBeTrue();

    $session->status = Session::STATUS_REVOKED;
    $session->save();
    expect($session->fresh()->isLive())->toBeFalse();
});

it('isImpersonation() flips when actor is set', function (): void {
    $f = sessionFixture();
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'user_id' => $f['user']->id,
    ]);
    expect($session->isImpersonation())->toBeFalse();

    $session->actor = ['iss' => 'https://_admin.authn.local', 'sub' => 'user_op', 'sid' => 'sess_op'];
    $session->save();
    expect($session->fresh()->isImpersonation())->toBeTrue();
});

it('writes a SessionActivity row without touching the session row', function (): void {
    $f = sessionFixture();
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $f['client']->id,
        'user_id' => $f['user']->id,
    ]);
    $originalUpdatedAt = $session->updated_at;

    SessionActivity::create([
        'session_id' => $session->id,
        'device_type' => 'browser',
        'is_mobile' => false,
        'browser_name' => 'Chrome',
        'browser_version' => '120.0',
        'ip_address' => '127.0.0.1',
        'city' => 'Test',
        'country' => 'BR',
    ]);

    // Avoid timing flakiness — just assert the row exists and the session
    // wasn't auto-touched.
    expect($session->activities()->count())->toBe(1);
    expect($session->refresh()->updated_at->getTimestamp())->toBe($originalUpdatedAt->getTimestamp());
});
