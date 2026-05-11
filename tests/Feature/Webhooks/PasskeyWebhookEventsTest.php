<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Passkey;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Http\Bapi\BapiTestSupport;
use Tests\Feature\Http\Me\MeTestSupport;

it('emits passkey.removed webhook event when a passkey is removed via BAPI', function (): void {
    $boot = BapiTestSupport::bootEnv('pkadminhook');
    app()->instance(Environment::class, $boot['env']);
    $user = User::create(['environment_id' => $boot['env']->id]);
    $passkey = Passkey::factory()->create(['user_id' => $user->id]);

    test()->deleteJson(
        BapiTestSupport::url('/passkeys/'.$passkey->id),
        [],
        BapiTestSupport::headers($boot['token']),
    )->assertOk();

    $events = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $boot['env']->id)
        ->where('type', 'passkey.removed')
        ->get();
    expect($events)->toHaveCount(1);
    $data = $events->first()->data;
    expect($data['object'])->toBe('passkey');
    expect($data['id'])->toBe($passkey->id);
});

it('logs auth.mfa.passkey_removed when admin BAPI deletes a passkey', function (): void {
    Log::spy();
    $boot = BapiTestSupport::bootEnv('pkadminaudit');
    app()->instance(Environment::class, $boot['env']);
    $user = User::create(['environment_id' => $boot['env']->id]);
    $passkey = Passkey::factory()->create(['user_id' => $user->id]);

    test()->deleteJson(
        BapiTestSupport::url('/passkeys/'.$passkey->id),
        [],
        BapiTestSupport::headers($boot['token']),
    )->assertOk();

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $ctx) use ($user, $boot): bool {
        return $message === 'auth.mfa.passkey_removed'
            && $ctx['user_id'] === $user->id
            && $ctx['environment_id'] === $boot['env']->id
            && $ctx['surface'] === 'bapi'
            && $ctx['actor_type'] === 'operator';
    })->once();
});

it('emits passkey.removed and logs auth.mfa.passkey_removed when FAPI DELETE /me/passkeys runs', function (): void {
    Log::spy();
    $f = MeTestSupport::bootEnv();
    $auth = MeTestSupport::makeAuthenticatedUser($f['env']);
    $passkey = Passkey::factory()->create(['user_id' => $auth['user']->id]);

    test()->withCredentials()
        ->withHeaders([
            'Host' => 'acme.authn.local',
            'Origin' => 'https://app.example.com',
            'Authorization' => 'Bearer '.$auth['jwt'],
        ])
        ->deleteJson("https://acme.authn.local/v1/me/passkeys/{$passkey->id}")
        ->assertOk();

    $events = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('type', 'passkey.removed')
        ->get();
    expect($events)->toHaveCount(1);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $ctx) use ($auth, $f): bool {
        return $message === 'auth.mfa.passkey_removed'
            && $ctx['user_id'] === $auth['user']->id
            && $ctx['environment_id'] === $f['env']->id
            && $ctx['surface'] === 'fapi'
            && $ctx['actor_type'] === 'user';
    })->once();
});

it('emits instance.config.appearance_updated with previous + current + diff on PUT', function (): void {
    $boot = BapiTestSupport::bootEnv('apphook');
    app()->instance(Environment::class, $boot['env']);

    test()->putJson(
        BapiTestSupport::url('/instance/appearance'),
        [
            'variables' => ['colorPrimary' => '#0a84ff'],
            'layout' => ['socialButtonsPlacement' => 'top'],
        ],
        BapiTestSupport::headers($boot['token']),
    )->assertOk();

    $event = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $boot['env']->id)
        ->where('type', 'instance.config.appearance_updated')
        ->latest('created_at')
        ->first();
    expect($event)->not->toBeNull();
    expect($event->data)->toHaveKey('previous');
    expect($event->data)->toHaveKey('current');
    expect($event->data)->toHaveKey('diff');
    expect($event->data['current']['variables']['colorPrimary'])->toBe('#0a84ff');
    // Variables axis diff: colorPrimary added.
    $varsDiff = $event->data['diff']['variables'];
    expect((array) $varsDiff['added'])->toHaveKey('colorPrimary');
});

it('emits localization.updated with previous + current + diff + override_etag on PUT', function (): void {
    $boot = BapiTestSupport::bootEnv('lochook');
    app()->instance(Environment::class, $boot['env']);

    test()->putJson(
        BapiTestSupport::url('/instance/localization'),
        [
            'default_locale' => 'pt-BR',
            'fallback_locale' => 'en-US',
            'supported_locales' => ['en-US', 'pt-BR'],
            'overrides' => [
                'pt-BR' => ['signIn.start.title' => 'Bem-vindo à {applicationName}'],
            ],
        ],
        BapiTestSupport::headers($boot['token']),
    )->assertOk();

    $event = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $boot['env']->id)
        ->where('type', 'localization.updated')
        ->latest('created_at')
        ->first();
    expect($event)->not->toBeNull();
    expect($event->data)->toHaveKey('previous');
    expect($event->data)->toHaveKey('current');
    expect($event->data)->toHaveKey('diff');
    expect($event->data)->toHaveKey('override_etag');
    expect($event->data['current']['default_locale'])->toBe('pt-BR');
    expect($event->data['override_etag'])->toStartWith('sha256:');
});

it('does not emit a webhook event when the appearance PUT is a no-op', function (): void {
    $boot = BapiTestSupport::bootEnv('apnoop');
    app()->instance(Environment::class, $boot['env']);

    // Two identical PUTs in a row — second one should be a no-op.
    $payload = ['variables' => ['colorPrimary' => '#0a84ff']];
    test()->putJson(BapiTestSupport::url('/instance/appearance'), $payload, BapiTestSupport::headers($boot['token']))->assertOk();
    test()->putJson(BapiTestSupport::url('/instance/appearance'), $payload, BapiTestSupport::headers($boot['token']))->assertOk();

    $count = WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $boot['env']->id)
        ->where('type', 'instance.config.appearance_updated')
        ->count();
    expect($count)->toBe(1);
});
