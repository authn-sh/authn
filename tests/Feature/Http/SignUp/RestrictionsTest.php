<?php

declare(strict_types=1);

use App\Models\AllowlistIdentifier;
use App\Models\BlocklistIdentifier;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\User;
use Tests\Feature\Http\SignUp\SignUpTestSupport;

it('rejects fields the env has disabled with form_param_unknown', function (): void {
    $f = SignUpTestSupport::bootEnv([
        'attributes' => [
            // Disable username explicitly (default is also disabled).
            'username' => ['enabled' => false],
        ],
    ]);
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ups', [
            'email_address' => 'a@example.com',
            'password' => 'super-secret-password',
            'username' => 'shouldbeignored',
        ]);

    $r->assertStatus(422)->assertJsonPath('errors.0.code', 'form_param_unknown');
});

it('only allows allowlisted emails when signup_mode = restricted', function (): void {
    $f = SignUpTestSupport::bootEnv([], Environment::SIGNUP_MODE_RESTRICTED);
    AllowlistIdentifier::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'identifier' => 'team.com',
        'identifier_type' => AllowlistIdentifier::TYPE_EMAIL_DOMAIN,
    ]);
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    // Outside the allowlist domain → rejected.
    $bad = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ups', [
            'email_address' => 'someone@outside.com',
            'password' => 'super-secret-password',
        ]);
    $bad->assertStatus(422)->assertJsonPath('errors.0.code', 'form_identifier_not_allowed');

    // Inside the allowlist domain → accepted.
    $good = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ups', [
            'email_address' => 'someone@team.com',
            'password' => 'super-secret-password',
        ]);
    $good->assertOk()->assertJsonPath('response.status', 'missing_requirements');
});

it('rejects blocklisted emails', function (): void {
    $f = SignUpTestSupport::bootEnv();
    BlocklistIdentifier::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'identifier' => 'banned@example.com',
        'identifier_type' => BlocklistIdentifier::TYPE_EMAIL_ADDRESS,
    ]);
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ups', [
            'email_address' => 'banned@example.com',
            'password' => 'super-secret-password',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'form_identifier_not_allowed');
});

it('detects subaddress collisions when block_email_subaddresses is on', function (): void {
    $f = SignUpTestSupport::bootEnv([
        'block_email_subaddresses' => true,
    ]);
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    // Pre-seed a verified user with foo@example.com.
    $user = User::query()->withoutGlobalScopes()->create(['environment_id' => $f['env']->id]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'email_address' => 'foo@example.com',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ups', [
            'email_address' => 'foo+anything@example.com',
            'password' => 'super-secret-password',
        ]);
    $r->assertOk()->assertJsonPath('response.status', 'transferable')->assertJsonPath('response.target_flow', 'sign_in');
});

it('treats gmail dot variants as the same identifier', function (): void {
    $f = SignUpTestSupport::bootEnv(); // ignore_dots_for_gmail_addresses defaults to true

    // Pre-seed a verified user with foo@gmail.com.
    $user = User::query()->withoutGlobalScopes()->create(['environment_id' => $f['env']->id]);
    EmailAddress::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'email_address' => 'foo@gmail.com',
        'verified_at' => now(),
        'is_primary' => true,
    ]);
    $bs = SignUpTestSupport::clientWithCookie($f['env']);

    $r = $this->withCredentials()
        ->withUnencryptedCookie('__client', $bs['cookie'])
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson('https://acme.authn.local/v1/client/sign-ups', [
            'email_address' => 'f.o.o@gmail.com',
            'password' => 'super-secret-password',
        ]);
    $r->assertOk()->assertJsonPath('response.status', 'transferable')->assertJsonPath('response.target_flow', 'sign_in');
});
