<?php

declare(strict_types=1);

use App\Auth\BruteForce\RecordFailedAttempt;
use App\Auth\TestMode\Detector;
use App\Auth\TestMode\Policy;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('Detector matches +authn_test emails (case-insensitive)', function (): void {
    expect(Detector::isTestEmail('alice+authn_test@example.com'))->toBeTrue();
    expect(Detector::isTestEmail('+AUTHN_TEST@anywhere.dev'))->toBeTrue();
    expect(Detector::isTestEmail('alice+other@example.com'))->toBeFalse();
    expect(Detector::isTestEmail('not-an-email'))->toBeFalse();
});

it('Detector matches the NANP 555-0100..0199 range', function (): void {
    expect(Detector::isTestPhone('+15555550100'))->toBeTrue();
    expect(Detector::isTestPhone('+15555550199'))->toBeTrue();
    expect(Detector::isTestPhone('+15555550200'))->toBeFalse();
    expect(Detector::isTestPhone('+15555550099'))->toBeFalse();
    expect(Detector::isTestPhone('15555550100'))->toBeFalse();
});

it('Detector combines email + phone via isTestIdentifier', function (): void {
    expect(Detector::isTestIdentifier('user+authn_test@x.com'))->toBeTrue();
    expect(Detector::isTestIdentifier('+15555550150'))->toBeTrue();
    expect(Detector::isTestIdentifier('regular@x.com'))->toBeFalse();
});

it('Policy returns REJECTED in production by default and TEST in development', function (): void {
    $project = Project::create(['name' => 'P', 'slug' => 'p']);
    $prod = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'prod-tm',
        'routing_label' => 'prod-tm',
        'allowed_origins' => [],
    ]);
    $dev = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_DEVELOPMENT,
        'slug' => 'dev-tm',
        'routing_label' => 'dev-tm',
        'allowed_origins' => [],
    ]);

    expect(Policy::resolve($prod, 'alice+authn_test@example.com'))->toBe(Policy::STATUS_REJECTED);
    expect(Policy::resolve($dev, 'alice+authn_test@example.com'))->toBe(Policy::STATUS_TEST);
    expect(Policy::resolve($dev, 'alice@example.com'))->toBe(Policy::STATUS_NORMAL);
});

it('brute-force counter ignores test identifiers', function (): void {
    Cache::flush();
    $project = Project::create(['name' => 'P', 'slug' => 'p-bf']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_DEVELOPMENT,
        'slug' => 'dev-bf',
        'routing_label' => 'dev-bf',
        'allowed_origins' => [],
        'user_settings' => ['attack_protection' => ['brute_force' => ['enabled' => true, 'max_attempts' => 3, 'lockout_duration_seconds' => 60]]],
    ]);
    $user = new User(['environment_id' => $env->id]);
    $user->save();

    $service = app(RecordFailedAttempt::class);
    for ($i = 0; $i < 5; $i++) {
        $service->record($env, '+authn_test@example.com', $user);
    }
    expect($user->fresh()->locked)->toBeFalse();

    // Real identifier locks at threshold.
    for ($i = 0; $i < 3; $i++) {
        $service->record($env, 'real@example.com', $user);
    }
    expect($user->fresh()->locked)->toBeTrue();
});
