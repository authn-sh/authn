<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use App\Models\SmsTemplate;
use App\Sms\DriverManager;
use App\Sms\Drivers\NullDriver;
use App\Sms\SmsEnvelope;
use App\Sms\SmsPipeline;
use App\Sms\SmsReceipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function envForPipeline(): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-pipe']);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'pipe',
        'routing_label' => 'pipe',
    ]);
}

it('short-circuits the reserved +1 555 555 0100-0199 range without hitting any driver', function (): void {
    config()->set('authn-sms.default_driver', 'null');
    Http::fake();
    $env = envForPipeline();

    $receipt = app(SmsPipeline::class)->dispatch(
        environment: $env,
        templateSlug: SmsTemplate::SLUG_VERIFICATION_CODE,
        toNumber: '+15555550100',
        vars: ['otp_code' => '424242'],
    );

    expect($receipt)->toBeNull();
    Http::assertNothingSent();
});

it('treats env user_settings.test_mode = enabled as a global short-circuit', function (): void {
    Http::fake();
    $env = envForPipeline();
    $env->forceFill(['user_settings' => ['test_mode' => 'enabled']])->save();

    $receipt = app(SmsPipeline::class)->dispatch(
        environment: $env->refresh(),
        templateSlug: SmsTemplate::SLUG_VERIFICATION_CODE,
        toNumber: '+14155551212',
        vars: ['otp_code' => '424242'],
    );

    expect($receipt)->toBeNull();
});

it('renders the body and dispatches via the env driver', function (): void {
    config()->set('authn-sms.default_driver', 'null');
    Cache::flush();
    $env = envForPipeline();

    $receipt = app(SmsPipeline::class)->dispatch(
        environment: $env,
        templateSlug: SmsTemplate::SLUG_VERIFICATION_CODE,
        toNumber: '+14155551212',
        vars: ['otp_code' => '424242', 'expiry_minutes' => 10],
    );

    expect($receipt)->not->toBeNull();
    expect($receipt->driver)->toBe('null');
    expect($receipt->accepted)->toBeTrue();
});

it('webhook-only delivery emits sms.created without calling a driver', function (): void {
    Http::fake();
    Cache::flush();
    $env = envForPipeline();
    SmsTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('slug', SmsTemplate::SLUG_VERIFICATION_CODE)
        ->update(['delivered_by_us' => false]);

    // Substitute a driver that fails the test if called.
    app(DriverManager::class)->extend('null', fn () => new class extends NullDriver
    {
        public function send(SmsEnvelope $envelope): SmsReceipt
        {
            throw new RuntimeException('driver should not be reached when delivered_by_us=false');
        }
    });
    config()->set('authn-sms.default_driver', 'null');

    $receipt = app(SmsPipeline::class)->dispatch(
        environment: $env,
        templateSlug: SmsTemplate::SLUG_VERIFICATION_CODE,
        toNumber: '+14155551212',
        vars: ['otp_code' => '424242', 'expiry_minutes' => 10],
    );

    expect($receipt)->not->toBeNull();
    expect($receipt->driver)->toBe('webhook');
});

it('isReservedTestNumber matches only the 0100-0199 range', function (): void {
    expect(SmsPipeline::isReservedTestNumber('+15555550100'))->toBeTrue();
    expect(SmsPipeline::isReservedTestNumber('+15555550199'))->toBeTrue();
    expect(SmsPipeline::isReservedTestNumber('+1 (555) 555-0142'))->toBeTrue();
    expect(SmsPipeline::isReservedTestNumber('+15555550200'))->toBeFalse();
    expect(SmsPipeline::isReservedTestNumber('+14155551212'))->toBeFalse();
});
