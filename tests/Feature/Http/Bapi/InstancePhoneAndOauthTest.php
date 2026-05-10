<?php

declare(strict_types=1);

use App\Http\Resources\EnvironmentResource;
use App\Models\Environment;
use App\Models\OauthProvider;
use App\Models\WebhookEvent;
use Tests\Feature\Http\Bapi\BapiTestSupport;

it('PATCH /instance writes through multi_factor.phone_code.enabled', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'multi_factor' => ['phone_code' => ['enabled' => true]],
        ])
        ->assertOk()
        ->assertJsonPath('multi_factor.phone_code.enabled', true);

    $env = Environment::query()->withoutGlobalScopes()->where('id', $f['env']->id)->first();
    $this->assertTrue($env->user_settings['multi_factor']['phone_code']['enabled']);
});

it('PATCH /instance emits instance.config.multi_factor_updated when phone_code flips', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'multi_factor' => ['phone_code' => ['enabled' => true]],
        ])
        ->assertOk();

    $events = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('type', 'instance.config.multi_factor_updated')
        ->get();
    expect($events)->toHaveCount(1);
});

it('PATCH /instance accepts attributes.phone_number tri-state and emits attributes_updated', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'attributes' => ['phone_number' => 'optional'],
        ])
        ->assertOk()
        ->assertJsonPath('attribute_settings.phone_number.enabled', true)
        ->assertJsonPath('attribute_settings.phone_number.required', false);

    $env = Environment::query()->withoutGlobalScopes()->where('id', $f['env']->id)->first();
    $this->assertSame('optional', $env->user_settings['attributes']['phone_number']);

    $events = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('type', 'instance.config.attributes_updated')
        ->get();
    expect($events)->toHaveCount(1);
});

it('PATCH /instance rejects unknown phone_number values', function (): void {
    $f = BapiTestSupport::bootEnv();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'attributes' => ['phone_number' => 'maybe'],
        ])
        ->assertStatus(422);
});

it('GET /environment narrows first_factors and oauth_providers based on env state', function (): void {
    $f = BapiTestSupport::bootEnv('env10');

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/instance'), [
            'attributes' => ['phone_number' => 'optional'],
            'multi_factor' => ['phone_code' => ['enabled' => true]],
        ])
        ->assertOk();

    OauthProvider::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'provider_kind' => OauthProvider::KIND_PRESET,
        'provider_key' => 'google',
        'name' => 'Google',
        'enabled' => true,
        'client_id' => 'gid',
        'encrypted_client_secret' => 'gsecret',
    ]);

    // Render the resource directly — full FAPI route reload would clobber
    // the BAPI routes the bootEnv() above just installed.
    $payload = EnvironmentResource::from($f['env']->refresh());

    $firstFactors = $payload['auth_config']['first_factors'];
    expect($firstFactors)->toContain('phone_code');
    expect($firstFactors)->toContain('oauth_google');
    expect($payload['auth_config']['identifier_requirements']['phone_number'])->toBe('optional');
    expect($payload['auth_config']['second_factors'])->toContain('phone_code');
    expect(collect($payload['oauth_providers'])->pluck('provider_key')->all())->toContain('google');
});
