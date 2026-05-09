<?php

declare(strict_types=1);

use App\Models\Environment;
use Tests\Feature\Http\Bapi\BapiTestSupport;


it('reads + patches the env-level instance settings', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/instance'))
        ->assertOk()
        ->assertJsonPath('test_mode', 'rejected');

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'support_email' => 'help@example.com',
            'test_mode' => 'enabled',
        ])->assertOk()
        ->assertJsonPath('support_email', 'help@example.com')
        ->assertJsonPath('test_mode', 'enabled');

    expect($f['env']->fresh()->user_settings['test_mode'])->toBe('enabled');
});

it('PATCH /instance/restrictions mirrors allowlist_enabled into signup_mode', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance/restrictions'), [
            'allowlist_enabled' => true,
            'block_email_subaddresses' => true,
        ])->assertOk();

    $env = Environment::query()->withoutGlobalScopes()->where('id', $f['env']->id)->first();
    expect($env->signup_mode)->toBe('restricted');
    expect($env->user_settings['restrictions']['block_email_subaddresses'])->toBeTrue();
});

it('PATCH /instance/organization_settings is a v0.1 placeholder', function (): void {
    $f = BapiTestSupport::bootEnv();
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance/organization_settings'), ['enabled' => true])
        ->assertOk()->assertJsonPath('enabled', false);
});
