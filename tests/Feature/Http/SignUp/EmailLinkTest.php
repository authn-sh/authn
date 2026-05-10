<?php

declare(strict_types=1);

use App\Jobs\Mail\SendMagicLinkEmail;
use App\Models\Client;
use App\Models\SignUpAttempt;
use App\Models\Verification;
use App\Services\Client\ClientResolver;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

it('challenge email_link issues a magic link, dispatches the email job', function (): void {
    Bus::fake([SendMagicLinkEmail::class]);
    $f = SessionsTestSupport::bootEnv(userSettings: ['identifiers' => ['email_address' => ['enabled' => true, 'used_for_first_factor' => true, 'verifications' => ['email_code', 'email_link']]]]);
    $client = Client::create(['environment_id' => $f['env']->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($client);
    $attempt = SignUpAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'email_address' => 'newbie@example.com',
        'status' => SignUpAttempt::STATUS_MISSING_REQUIREMENTS,
        'unverified_fields' => ['email_address'],
    ]);

    $r = test()->withCredentials()->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ups/{$attempt->id}/challenges", [
            'strategy' => 'email_link',
        ]);
    $r->assertOk()
        ->assertJsonPath('response.object', 'challenge')
        ->assertJsonPath('response.strategy', 'email_link')
        ->assertJsonPath('response.status', 'pending');

    $verification = Verification::query()->withoutGlobalScopes()
        ->where('verifiable_type', $attempt->getMorphClass())
        ->where('verifiable_id', $attempt->id)
        ->where('strategy', 'email_link')
        ->firstOrFail();
    expect($verification->status)->toBe('unverified');

    Bus::assertDispatched(SendMagicLinkEmail::class, fn ($job) => $job->emailAddress === 'newbie@example.com'
        && $job->templateSlug === 'magic_link_sign_up');
});

it('answer email_link returns verification_failed while link is unredeemed', function (): void {
    Bus::fake([SendMagicLinkEmail::class]);
    $f = SessionsTestSupport::bootEnv();
    $client = Client::create(['environment_id' => $f['env']->id]);
    $cookie = app(ClientResolver::class)->mintCookieValue($client);
    $attempt = SignUpAttempt::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'email_address' => 'newbie@example.com',
        'status' => SignUpAttempt::STATUS_MISSING_REQUIREMENTS,
        'unverified_fields' => ['email_address'],
    ]);

    $issue = test()->withCredentials()->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ups/{$attempt->id}/challenges", [
            'strategy' => 'email_link',
        ]);
    $issue->assertOk();
    $cid = $issue->json('response.id');

    test()->withCredentials()->withUnencryptedCookie('__client', $cookie)
        ->withHeaders(['Host' => 'acme.authn.local', 'Origin' => $f['origin']])
        ->postJson("https://acme.authn.local/v1/client/sign-ups/{$attempt->id}/challenges/{$cid}/answer", [])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'verification_failed');
});
