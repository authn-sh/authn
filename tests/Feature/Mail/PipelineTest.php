<?php

declare(strict_types=1);

use App\Jobs\Mail\SendVerificationEmail;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Mail\MailTestSupport;

beforeEach(function (): void {
    Cache::flush();
    config([
        'authn-mail.default_driver' => 'resend',
        'authn-mail.drivers.resend.api_key' => 'test-key',
    ]);
});

it('honours +authn_test recipients (no driver call)', function (): void {
    $env = MailTestSupport::bootEnv();
    Http::fake();
    Log::spy();

    SendVerificationEmail::dispatchSync(
        $env->id,
        'alice+authn_test@example.com',
        '424242',
        'email_code',
    );

    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->withArgs(fn ($message) => $message === 'mail_skipped_test_mode');
});

it('honours delivered_by_us=false (logs email.created instead of calling the driver)', function (): void {
    $env = MailTestSupport::bootEnv();
    EmailTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('slug', EmailTemplate::SLUG_VERIFICATION_CODE)
        ->update(['delivered_by_us' => false]);
    $bs = MailTestSupport::makeUserWithEmail($env, 'doe@example.com');
    $verification = MailTestSupport::startVerification($bs['email']);

    Http::fake();
    Log::spy();

    SendVerificationEmail::dispatchSync(
        $env->id,
        'doe@example.com',
        '424242',
        'email_code',
        $verification->id,
        $bs['email']->id,
    );

    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->withArgs(fn ($message) => $message === 'email.created (delivered_by_us=false)');
});

it('debounces a second send within 60s', function (): void {
    $env = MailTestSupport::bootEnv();
    $bs = MailTestSupport::makeUserWithEmail($env, 'debounce@example.com');
    $verification = MailTestSupport::startVerification($bs['email']);
    Http::fake([
        'api.resend.com/*' => Http::response(['id' => 'msg_1'], 200),
    ]);

    SendVerificationEmail::dispatchSync(
        $env->id,
        'debounce@example.com',
        '424242',
        'email_code',
        $verification->id,
        $bs['email']->id,
    );
    SendVerificationEmail::dispatchSync(
        $env->id,
        'debounce@example.com',
        '424242',
        'email_code',
        $verification->id,
        $bs['email']->id,
    );

    Http::assertSentCount(1);
});

it('routes through the configured driver on the happy path', function (): void {
    $env = MailTestSupport::bootEnv();
    $bs = MailTestSupport::makeUserWithEmail($env, 'driver@example.com');
    $verification = MailTestSupport::startVerification($bs['email']);
    Http::fake([
        'api.resend.com/*' => Http::response(['id' => 'msg_driver_1'], 200),
    ]);

    SendVerificationEmail::dispatchSync(
        $env->id,
        'driver@example.com',
        '424242',
        'email_code',
        $verification->id,
        $bs['email']->id,
    );

    Http::assertSent(fn ($req) => str_contains($req->url(), 'resend.com/emails'));
});
