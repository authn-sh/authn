<?php

declare(strict_types=1);

use App\Settings\PasskeySettings;

it('defaults to enabled and counts_as_mfa = true when user_settings is empty', function (): void {
    $settings = PasskeySettings::fromUserSettings(null);

    expect($settings->enabled)->toBeTrue();
    expect($settings->passkeyCountsAsMfa)->toBeTrue();
});

it('reads authentication_strategies.passkey.enabled from user_settings', function (): void {
    $settings = PasskeySettings::fromUserSettings([
        'authentication_strategies' => ['passkey' => ['enabled' => false]],
    ]);

    expect($settings->enabled)->toBeFalse();
});

it('reads multi_factor.passkey_counts_as_mfa from user_settings', function (): void {
    $settings = PasskeySettings::fromUserSettings([
        'multi_factor' => ['passkey_counts_as_mfa' => false],
    ]);

    expect($settings->passkeyCountsAsMfa)->toBeFalse();
});

it('round-trips through toArray()', function (): void {
    $settings = new PasskeySettings(enabled: false, passkeyCountsAsMfa: false);
    $blob = $settings->toArray();

    expect($blob['authentication_strategies']['passkey']['enabled'])->toBeFalse();
    expect($blob['multi_factor']['passkey_counts_as_mfa'])->toBeFalse();

    $reloaded = PasskeySettings::fromUserSettings($blob);
    expect($reloaded->enabled)->toBeFalse();
    expect($reloaded->passkeyCountsAsMfa)->toBeFalse();
});
