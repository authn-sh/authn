<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Session;
use App\Models\SessionActivity;
use App\Models\User;
use App\Services\Sessions\SessionLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

function lifecycleFixture(?string $suffix = null): array
{
    $suffix ??= bin2hex(random_bytes(3));
    $project = Project::create(['name' => 'P-'.$suffix, 'slug' => 'p-'.$suffix]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'env-'.$suffix,
        'routing_label' => 'env-'.$suffix,
    ]);
    $client = Client::create(['environment_id' => $env->id]);
    $user = User::create(['environment_id' => $env->id]);
    $session = Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);
    Cache::flush();

    return ['session' => $session, 'env' => $env];
}

it('writes a SessionActivity row on first touch', function (): void {
    $f = lifecycleFixture();
    $request = Request::create('http://acme.authn.local/v1/anything');
    $request->headers->set('User-Agent', 'Mozilla/5.0 Chrome/120.0 Safari/537');

    expect(SessionActivity::count())->toBe(0);

    app(SessionLifecycle::class)->touch($f['session'], $request);

    expect(SessionActivity::count())->toBe(1);
    expect(SessionActivity::first()->browser_name)->toBe('Chrome');
});

it('debounces touches to once per minute per session', function (): void {
    $f = lifecycleFixture();
    $request = Request::create('http://x');

    $service = app(SessionLifecycle::class);
    $service->touch($f['session'], $request);
    $service->touch($f['session'], $request);
    $service->touch($f['session'], $request);

    expect(SessionActivity::count())->toBe(1);
});

it('end / remove / revoke / expire each flip status correctly', function (): void {
    $service = app(SessionLifecycle::class);

    foreach ([
        Session::STATUS_ENDED => 'end',
        Session::STATUS_REMOVED => 'remove',
        Session::STATUS_REVOKED => 'revoke',
        Session::STATUS_EXPIRED => 'expire',
    ] as $expected => $method) {
        $f = lifecycleFixture();
        $service->$method($f['session']);
        expect($f['session']->fresh()->status)->toBe($expected);
    }
});

it('refuses to flip a session that is already terminal', function (): void {
    $f = lifecycleFixture();
    $service = app(SessionLifecycle::class);

    $service->end($f['session']);
    // Once ended, end() is a no-op (target == current).
    expect(fn () => $service->end($f['session']))->not->toThrow(Throwable::class);

    // But trying to revoke an ended session crosses an illegal transition.
    expect(fn () => $service->revoke($f['session']->fresh()))
        ->toThrow(InvalidArgumentException::class);
});

it('activate transitions a pending session to active', function (): void {
    $project = Project::create(['name' => 'P', 'slug' => 'p2']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'env2',
        'routing_label' => 'env2',
    ]);
    $client = Client::create(['environment_id' => $env->id]);
    $user = User::create(['environment_id' => $env->id]);
    $session = Session::create([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_PENDING,
    ]);

    app(SessionLifecycle::class)->activate($session);

    expect($session->fresh()->status)->toBe(Session::STATUS_ACTIVE);
});
