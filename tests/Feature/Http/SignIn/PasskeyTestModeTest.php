<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Passkey;
use Tests\Feature\Http\SignIn\SignInTestSupport;

it('does not advertise passkey on supported_strategies for a test_mode identifier without passkeys', function (): void {
    // Strict-semantic: passkey requires the resolved user to actually hold a
    // verified passkey. Test-mode shortcuts don't synthesize a user with
    // passkey credentials, so the strategy should never be on the menu for
    // the +authn_test bypass path. A real user *without* a passkey under a
    // test-mode email proves the negative cleanly.
    $f = SignInTestSupport::bootEnv();
    $f['env']->forceFill([
        'user_settings' => array_merge(
            (array) $f['env']->user_settings,
            ['test_mode' => Environment::TEST_MODE_ENABLED],
        ),
    ])->save();
    SignInTestSupport::makeUser($f['env'], 'reserved+authn_test@example.com');
    $bs = SignInTestSupport::clientWithCookie($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'reserved+authn_test@example.com',
        ]);

    expect($r->json('response.supported_strategies') ?? [])->not->toContain('passkey');
});

it('lists passkey for a real user even when test_mode is enabled (no false negative for non-reserved identifiers)', function (): void {
    $f = SignInTestSupport::bootEnv();
    $f['env']->forceFill([
        'user_settings' => array_merge(
            (array) $f['env']->user_settings,
            ['test_mode' => Environment::TEST_MODE_ENABLED],
        ),
    ])->save();
    $bundle = SignInTestSupport::makeUser($f['env'], 'alice@example.com');
    Passkey::factory()->create(['user_id' => $bundle['user']->id, 'verified_at' => now()]);
    $bs = SignInTestSupport::clientWithCookie($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);

    expect($r->json('response.supported_strategies'))->toContain('passkey');
});
