<?php

declare(strict_types=1);

use App\Models\Passkey;
use Tests\Feature\Http\SignIn\SignInTestSupport;

it('lists passkey in supported_strategies when the user has a verified passkey', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    Passkey::factory()->create(['user_id' => $bundle['user']->id, 'verified_at' => now()]);
    $bs = SignInTestSupport::clientWithCookie($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);

    $r->assertOk();
    $strategies = $r->json('response.supported_strategies');
    expect($strategies)->toContain('passkey');
});

it('omits passkey from supported_strategies when the user has no verified passkey', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);
    $bs = SignInTestSupport::clientWithCookie($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);

    $r->assertOk();
    $strategies = $r->json('response.supported_strategies');
    expect($strategies)->not->toContain('passkey');
});

it('omits passkey when only an unverified passkey exists', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    Passkey::factory()->unverified()->create(['user_id' => $bundle['user']->id]);
    $bs = SignInTestSupport::clientWithCookie($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);

    $strategies = $r->json('response.supported_strategies');
    expect($strategies)->not->toContain('passkey');
});

it('POST /challenges with strategy=passkey returns request_options on the Challenge', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    Passkey::factory()->create(['user_id' => $bundle['user']->id, 'verified_at' => now()]);
    $bs = SignInTestSupport::clientWithCookie($f['env']);

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', [
            'identifier' => 'alice@example.com',
        ]);
    $sid = $create->json('response.id');

    $issue = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", [
            'strategy' => 'passkey',
        ]);

    $issue->assertOk()
        ->assertJsonPath('response.object', 'challenge')
        ->assertJsonPath('response.strategy', 'passkey')
        ->assertJsonPath('response.status', 'pending');
    $cid = $issue->json('response.id');
    expect($cid)->toStartWith('chal_');

    $reqOpts = $issue->json('response.request_options');
    expect($reqOpts)->not->toBeNull();
    expect($reqOpts['rp_id'])->toBe('acme.authn.local');
    expect($reqOpts['allow_credentials'])->toHaveCount(1);
});

it('POST /challenges/{cid}/answer with a malformed assertion returns a passkey error code', function (): void {
    $f = SignInTestSupport::bootEnv();
    $bundle = SignInTestSupport::makeUser($f['env']);
    Passkey::factory()->create(['user_id' => $bundle['user']->id, 'verified_at' => now()]);
    $bs = SignInTestSupport::clientWithCookie($f['env']);

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', ['identifier' => 'alice@example.com']);
    $sid = $create->json('response.id');

    $issue = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", ['strategy' => 'passkey']);
    $cid = $issue->json('response.id');

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges/{$cid}/answer", [
            'assertion' => [
                'id' => 'aGVsbG8',
                'rawId' => 'aGVsbG8',
                'type' => 'public-key',
                'response' => ['authenticatorData' => '!!', 'clientDataJSON' => '!!', 'signature' => '!!'],
            ],
        ]);

    $r->assertStatus(422);
    expect($r->json('errors.0.code'))->toBeIn([
        'passkey_assertion_invalid',
        'passkey_origin_mismatch',
        'passkey_attestation_invalid',
        'form_param_format_invalid',
    ]);
});

it('rejects strategy=passkey when supported_strategies excludes it', function (): void {
    $f = SignInTestSupport::bootEnv();
    SignInTestSupport::makeUser($f['env']);
    $bs = SignInTestSupport::clientWithCookie($f['env']);

    $create = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ins', ['identifier' => 'alice@example.com']);
    $sid = $create->json('response.id');

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ins/{$sid}/challenges", ['strategy' => 'passkey']);

    $r->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'strategy_not_supported');
});
