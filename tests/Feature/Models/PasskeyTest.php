<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Passkey;
use App\Models\Project;
use App\Models\User;
use App\Models\Verification;

function makeEnvForPasskey(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints a pkey_ prefixed id and casts transports to an array', function (): void {
    $env = makeEnvForPasskey();
    $user = User::create(['environment_id' => $env->id]);

    $passkey = Passkey::factory()->create([
        'user_id' => $user->id,
        'transports' => [Passkey::TRANSPORT_INTERNAL, Passkey::TRANSPORT_HYBRID],
    ]);

    expect($passkey->id)->toStartWith('pkey_');
    expect($passkey->transports)->toBe([Passkey::TRANSPORT_INTERNAL, Passkey::TRANSPORT_HYBRID]);
});

it('encrypts credential_id and public_key at rest and hides them from arrays', function (): void {
    $env = makeEnvForPasskey();
    $user = User::create(['environment_id' => $env->id]);

    $credentialId = random_bytes(32);
    $passkey = Passkey::factory()->create([
        'user_id' => $user->id,
        'credential_id' => $credentialId,
        'credential_id_hash' => hash('sha256', $credentialId, true),
        'public_key' => 'cleartext-public-key',
    ]);

    expect($passkey->credential_id)->toBe($credentialId);
    expect($passkey->public_key)->toBe('cleartext-public-key');

    $raw = (string) DB::table('passkeys')->where('id', $passkey->id)->value('public_key');
    expect($raw)->not->toBe('cleartext-public-key');
    expect(strlen($raw))->toBeGreaterThan(20);

    $arr = $passkey->toArray();
    expect($arr)->not->toHaveKey('credential_id');
    expect($arr)->not->toHaveKey('credential_id_hash');
    expect($arr)->not->toHaveKey('public_key');
});

it('relates Passkey → User and exposes User->passkeys()', function (): void {
    $env = makeEnvForPasskey();
    $user = User::create(['environment_id' => $env->id]);

    $passkey = Passkey::factory()->create(['user_id' => $user->id]);

    expect($passkey->user->id)->toBe($user->id);
    expect($user->refresh()->passkeys->pluck('id')->all())->toBe([$passkey->id]);
});

it('counts only verified passkeys via the passkey_count accessor', function (): void {
    $env = makeEnvForPasskey();
    $user = User::create(['environment_id' => $env->id]);

    Passkey::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    Passkey::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    Passkey::factory()->unverified()->create(['user_id' => $user->id]);

    expect($user->passkey_count)->toBe(2);
});

it('verified() and usable() scopes filter to verified, non-removed rows', function (): void {
    $env = makeEnvForPasskey();
    $user = User::create(['environment_id' => $env->id]);

    $verified = Passkey::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    Passkey::factory()->unverified()->create(['user_id' => $user->id]);
    $removed = Passkey::factory()->create(['user_id' => $user->id, 'verified_at' => now()]);
    $removed->delete();

    expect(Passkey::query()->verified()->pluck('id')->all())->toBe([$verified->id]);
    expect(Passkey::query()->usable()->pluck('id')->all())->toBe([$verified->id]);
});

it('soft-deletes via the removed_at column', function (): void {
    $env = makeEnvForPasskey();
    $user = User::create(['environment_id' => $env->id]);

    $passkey = Passkey::factory()->create(['user_id' => $user->id]);
    $passkey->delete();

    expect(Passkey::query()->find($passkey->id))->toBeNull();
    expect(Passkey::withTrashed()->find($passkey->id)?->removed_at)->not->toBeNull();
});

it('cascades passkey deletion when the parent user is deleted', function (): void {
    $env = makeEnvForPasskey();
    $user = User::create(['environment_id' => $env->id]);
    $passkey = Passkey::factory()->create(['user_id' => $user->id]);

    DB::table('users')->where('id', $user->id)->delete();

    expect(DB::table('passkeys')->where('id', $passkey->id)->exists())->toBeFalse();
});

it('extends Verification::strategies() with the passkey strategy', function (): void {
    expect(Verification::strategies())->toContain(Verification::STRATEGY_PASSKEY);
    expect(Verification::isValidStrategy('passkey'))->toBeTrue();
});
