<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use App\Models\TotpSecret;
use App\Models\User;

function makeEnvForTotp(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => $slug.'-p']);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints a totp_-prefixed id', function (): void {
    $env = makeEnvForTotp();
    $user = User::create(['environment_id' => $env->id]);

    $row = TotpSecret::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    expect($row->id)->toStartWith('totp_');
});

it('encrypts the secret column at rest and exposes plaintext via the cast', function (): void {
    $env = makeEnvForTotp('e2');
    $user = User::create(['environment_id' => $env->id]);

    $plain = 'JBSWY3DPEHPK3PXP';
    $row = TotpSecret::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'secret' => $plain,
    ]);

    expect($row->secret)->toBe($plain);

    $raw = DB::table('totp_secrets')->where('id', $row->id)->value('secret');
    expect($raw)->not->toBe($plain);
    expect(strlen((string) $raw))->toBeGreaterThan(strlen($plain));
});

it('hides the secret from array/JSON serialisation', function (): void {
    $env = makeEnvForTotp('e3');
    $user = User::create(['environment_id' => $env->id]);

    $row = TotpSecret::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    expect($row->toArray())->not->toHaveKey('secret');
});

it('defaults algorithm/digits/period_seconds to RFC 6238 values', function (): void {
    $env = makeEnvForTotp('e4');
    $user = User::create(['environment_id' => $env->id]);

    $row = TotpSecret::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
    ])->refresh();

    expect($row->algorithm)->toBe('SHA1');
    expect($row->digits)->toBe(6);
    expect($row->period_seconds)->toBe(30);
});

it('isVerified() reflects verified_at presence', function (): void {
    $env = makeEnvForTotp('e5');
    $user = User::create(['environment_id' => $env->id]);

    $row = TotpSecret::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
    ]);
    expect($row->isVerified())->toBeFalse();

    $row->forceFill(['verified_at' => now()])->save();
    expect($row->isVerified())->toBeTrue();
});

it('scopes queries to the bound environment', function (): void {
    $envA = makeEnvForTotp('ea');
    $envB = makeEnvForTotp('eb');
    $userA = User::create(['environment_id' => $envA->id]);
    $userB = User::create(['environment_id' => $envB->id]);

    TotpSecret::create([
        'environment_id' => $envA->id,
        'user_id' => $userA->id,
        'secret' => 'AAA',
    ]);
    TotpSecret::create([
        'environment_id' => $envB->id,
        'user_id' => $userB->id,
        'secret' => 'BBB',
    ]);

    app()->instance(Environment::class, $envA);
    expect(TotpSecret::query()->count())->toBe(1);

    app()->instance(Environment::class, $envB);
    expect(TotpSecret::query()->count())->toBe(1);
});
