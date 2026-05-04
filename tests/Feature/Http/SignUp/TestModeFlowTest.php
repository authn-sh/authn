<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Environment;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationCode;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Http\SignUp\SignUpTestSupport;

uses(RefreshDatabase::class);

it('sign-up against +authn_test in dev uses fixed code 424242, no driver call', function (): void {
    Bus::fake();
    $f = SignUpTestSupport::bootEnv();
    $f['env']->forceFill(['kind' => Environment::KIND_DEVELOPMENT, 'user_settings' => ['test_mode' => 'enabled']])->save();
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ups', [
            'email_address' => 'alice+authn_test@example.com',
            'password' => 'super-secret-password',
        ]);
    $create->assertOk()->assertJsonPath('response.status', 'missing_requirements');
    $sid = $create->json('response.id');

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ups/{$sid}/prepare_verification", ['strategy' => 'email_code'])
        ->assertOk();

    // The minted code is the fixed 424242 (still hashed in storage).
    $verification = Verification::query()->withoutGlobalScopes()->latest('id')->first();
    expect((bool) $verification->was_test)->toBeTrue();
    $codeRow = VerificationCode::query()->where('verification_id', $verification->id)->latest('id')->first();
    expect($codeRow->code_hash)->toBe(hash('sha256', '424242'));

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign_ups/{$sid}/attempt_verification", [
            'strategy' => 'email_code',
            'code' => '424242',
        ]);
    $r->assertOk()->assertJsonPath('response.status', 'complete');

    $userId = $r->json('response.created_user_id');
    expect($userId)->toStartWith('user_');
});

it('sign-up against +authn_test in production returns test_identifier_forbidden', function (): void {
    $f = SignUpTestSupport::bootEnv();
    // bootEnv defaults to KIND_PRODUCTION; the model creating hook stamps
    // test_mode = rejected.
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign_ups', [
            'email_address' => 'eve+authn_test@example.com',
            'password' => 'super-secret-password',
        ]);
    $r->assertStatus(422)->assertJsonPath('errors.0.code', 'test_identifier_forbidden');
    expect(User::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('PATCH /instance test_mode=enabled in production emits the audit event', function (): void {
    Bus::fake();
    $f = SignUpTestSupport::bootEnv();

    // Build an API key for the env so we can hit BAPI.
    $token = 'sk_live_'.str_repeat('p', 32);
    ApiKey::create([
        'environment_id' => $f['env']->id,
        'kind' => 'secret',
        'prefix' => substr($token, 0, 16),
        'hashed_secret' => hash('sha256', $token),
        'name' => 'Test',
    ]);

    config(['authn.bapi_host' => 'api.authn.local']);
    Cache::flush();

    // Reload BAPI routes alongside the FAPI ones already loaded by bootEnv.
    Route::middleware('bapi')
        ->prefix('v1')
        ->domain('api.authn.local')
        ->group(base_path('routes/bapi.php'));

    $r = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Host' => 'api.authn.local',
        'Accept' => 'application/json',
    ])->patchJson('http://api.authn.local/v1/instance', ['test_mode' => 'enabled']);
    $r->assertOk()->assertJsonPath('test_mode', 'enabled');

    expect(WebhookEvent::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('type', 'system.testmode_enabled_in_production')
        ->exists())->toBeTrue();
});
