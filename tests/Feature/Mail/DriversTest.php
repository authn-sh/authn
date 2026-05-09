<?php

declare(strict_types=1);

use App\Mail\DriverManager;
use App\Mail\Drivers\PostmarkDriver;
use App\Mail\Drivers\ResendDriver;
use App\Mail\Drivers\SesDriver;
use App\Mail\Drivers\SmtpDriver;
use App\Mail\Envelope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Mail\MailTestSupport;

function envelope(string $to = 'alice@example.com'): Envelope
{
    return new Envelope(
        fromEmail: 'noreply@authn.local',
        fromName: 'Authn',
        toEmail: $to,
        toName: null,
        subject: 'Hello',
        html: '<p>Hi</p>',
        text: 'Hi',
    );
}

it('Resend driver round-trips a fake send', function (): void {
    config(['authn-mail.drivers.resend.api_key' => 'test-key']);
    Http::fake([
        'api.resend.com/*' => Http::response(['id' => 'msg_resend_1'], 200),
    ]);

    $receipt = app(ResendDriver::class)->send(envelope());

    expect($receipt->accepted)->toBeTrue();
    expect($receipt->id)->toBe('msg_resend_1');
    Http::assertSent(fn ($req) => str_contains($req->url(), 'resend.com/emails'));
});

it('Postmark driver round-trips a fake send', function (): void {
    config(['authn-mail.drivers.postmark.api_key' => 'test-key']);
    Http::fake([
        'api.postmarkapp.com/*' => Http::response(['MessageID' => 'msg_pm_1', 'ErrorCode' => 0], 200),
    ]);

    $receipt = app(PostmarkDriver::class)->send(envelope());

    expect($receipt->accepted)->toBeTrue();
    expect($receipt->id)->toBe('msg_pm_1');
});

it('SES driver round-trips a fake send', function (): void {
    config([
        'authn-mail.drivers.ses.region' => 'us-east-1',
        'authn-mail.drivers.ses.access_key_id' => 'AKIA-test',
        'authn-mail.drivers.ses.secret_access_key' => 'secret-test',
    ]);
    Http::fake([
        'email.us-east-1.amazonaws.com/*' => Http::response(['MessageId' => 'msg_ses_1'], 200),
    ]);

    $receipt = app(SesDriver::class)->send(envelope());

    expect($receipt->accepted)->toBeTrue();
    expect($receipt->id)->toBe('msg_ses_1');
});

it('SMTP driver delegates to Mail::mailer(\'smtp\')', function (): void {
    Mail::fake();
    $receipt = app(SmtpDriver::class)->send(envelope());
    expect($receipt->accepted)->toBeTrue();
});

it('DriverManager honours the per-env override', function (): void {
    $env = MailTestSupport::bootEnv(['mail' => ['driver' => 'postmark']]);
    expect(app(DriverManager::class)->for($env))->toBeInstanceOf(PostmarkDriver::class);

    $envDefault = MailTestSupport::bootEnv();
    config(['authn-mail.default_driver' => 'smtp']);
    expect(app(DriverManager::class)->for($envDefault))->toBeInstanceOf(SmtpDriver::class);
});
