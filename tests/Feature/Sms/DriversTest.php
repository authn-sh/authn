<?php

declare(strict_types=1);

use App\Sms\Drivers\NullDriver;
use App\Sms\Drivers\TwilioDriver;
use App\Sms\Drivers\VonageDriver;
use App\Sms\SmsEnvelope;
use Illuminate\Support\Facades\Http;

function envelopeForDrivers(): SmsEnvelope
{
    return new SmsEnvelope(
        toNumber: '+15551231234',
        fromNumber: '+15550009999',
        body: 'Hello',
        templateSlug: 'verification_code',
    );
}

it('TwilioDriver POSTs form-encoded to the configured endpoint with basic auth', function (): void {
    config()->set('authn-sms.drivers.twilio.account_sid', 'AC123');
    config()->set('authn-sms.drivers.twilio.auth_token', 'tok456');
    config()->set('authn-sms.drivers.twilio.endpoint', 'https://api.twilio.com/Accounts/{AccountSid}/Messages.json');

    Http::fake([
        'api.twilio.com/*' => Http::response(['sid' => 'SM-001'], 201),
    ]);

    $receipt = app(TwilioDriver::class)->send(envelopeForDrivers());

    expect($receipt->driver)->toBe('twilio');
    expect($receipt->accepted)->toBeTrue();
    expect($receipt->id)->toBe('SM-001');

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'api.twilio.com/Accounts/AC123/Messages.json')
            && $request['To'] === '+15551231234'
            && $request['Body'] === 'Hello'
            && $request->hasHeader('Authorization');
    });
});

it('TwilioDriver throws when credentials are missing', function (): void {
    config()->set('authn-sms.drivers.twilio.account_sid', '');
    config()->set('authn-sms.drivers.twilio.auth_token', '');

    expect(fn () => app(TwilioDriver::class)->send(envelopeForDrivers()))
        ->toThrow(RuntimeException::class, 'Twilio SMS credentials');
});

it('VonageDriver POSTs form-encoded with api_key/api_secret', function (): void {
    config()->set('authn-sms.drivers.vonage.api_key', 'k1');
    config()->set('authn-sms.drivers.vonage.api_secret', 's1');
    config()->set('authn-sms.drivers.vonage.endpoint', 'https://rest.nexmo.com/sms/json');

    Http::fake([
        'rest.nexmo.com/sms/json' => Http::response([
            'messages' => [['status' => '0', 'message-id' => 'V-001']],
        ], 200),
    ]);

    $receipt = app(VonageDriver::class)->send(envelopeForDrivers());

    expect($receipt->driver)->toBe('vonage');
    expect($receipt->accepted)->toBeTrue();
    expect($receipt->id)->toBe('V-001');

    Http::assertSent(function ($request): bool {
        return $request['api_key'] === 'k1'
            && $request['api_secret'] === 's1'
            && $request['to'] === '+15551231234'
            && $request['text'] === 'Hello';
    });
});

it('VonageDriver flips accepted=false when status is non-zero', function (): void {
    config()->set('authn-sms.drivers.vonage.api_key', 'k');
    config()->set('authn-sms.drivers.vonage.api_secret', 's');

    Http::fake([
        'rest.nexmo.com/*' => Http::response([
            'messages' => [['status' => '4', 'error-text' => 'bad credentials']],
        ], 200),
    ]);

    $receipt = app(VonageDriver::class)->send(envelopeForDrivers());

    expect($receipt->accepted)->toBeFalse();
    expect($receipt->meta['vonage_status'] ?? null)->toBe('4');
});

it('NullDriver short-circuits + returns an accepted Receipt', function (): void {
    Http::fake();

    $receipt = app(NullDriver::class)->send(envelopeForDrivers());

    expect($receipt->driver)->toBe('null');
    expect($receipt->accepted)->toBeTrue();
    Http::assertNothingSent();
});
