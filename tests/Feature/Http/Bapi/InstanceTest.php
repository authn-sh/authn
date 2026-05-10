<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\WebhookEvent;
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

it('PATCH /instance/organization-settings is a v0.1 placeholder', function (): void {
    $f = BapiTestSupport::bootEnv();
    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance/organization-settings'), ['enabled' => true])
        ->assertOk()->assertJsonPath('enabled', false);
});

it('GET /instance emits multi_factor spec defaults when unset', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/instance'))
        ->assertOk()
        ->assertJsonPath('multi_factor.totp.enabled', true)
        ->assertJsonPath('multi_factor.backup_codes.enabled', true)
        ->assertJsonPath('multi_factor.backup_codes.default_count', 10);
});

it('GET /instance emits persisted multi_factor values when set', function (): void {
    $f = BapiTestSupport::bootEnv();
    $f['env']->forceFill([
        'user_settings' => array_merge((array) $f['env']->user_settings, [
            'multi_factor' => [
                'totp' => ['enabled' => false],
                'backup_codes' => ['enabled' => true, 'default_count' => 16],
            ],
        ]),
    ])->save();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/instance'))
        ->assertOk()
        ->assertJsonPath('multi_factor.totp.enabled', false)
        ->assertJsonPath('multi_factor.backup_codes.enabled', true)
        ->assertJsonPath('multi_factor.backup_codes.default_count', 16);
});

it('PATCH /instance writes through multi_factor with partial-patch semantics', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'multi_factor' => [
                'backup_codes' => ['default_count' => 8],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('multi_factor.totp.enabled', true)
        ->assertJsonPath('multi_factor.backup_codes.enabled', true)
        ->assertJsonPath('multi_factor.backup_codes.default_count', 8);

    expect($f['env']->fresh()->user_settings['multi_factor']['backup_codes']['default_count'])->toBe(8);
});

it('PATCH /instance rejects multi_factor.backup_codes.default_count outside 4..24', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'multi_factor' => ['backup_codes' => ['default_count' => 3]],
        ])
        ->assertStatus(422);

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'multi_factor' => ['backup_codes' => ['default_count' => 25]],
        ])
        ->assertStatus(422);
});

it('PATCH /instance emits an instance.config.multi_factor_updated webhook event when multi_factor changes', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'multi_factor' => ['totp' => ['enabled' => false]],
        ])
        ->assertOk();

    $event = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('type', 'instance.config.multi_factor_updated')
        ->first();
    expect($event)->not->toBeNull();
    expect($event->data['before']['totp']['enabled'])->toBeTrue();
    expect($event->data['after']['totp']['enabled'])->toBeFalse();
});

it('PATCH /instance does not emit the multi_factor webhook event when nothing changed', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'multi_factor' => ['totp' => ['enabled' => true]],
        ])
        ->assertOk();

    $count = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('type', 'instance.config.multi_factor_updated')
        ->count();
    expect($count)->toBe(0);
});
