<?php

declare(strict_types=1);

use App\Settings\EnterpriseSsoSettings;

it('defaults both toggles to true when user_settings is empty', function (): void {
    $settings = EnterpriseSsoSettings::fromUserSettings([]);

    expect($settings->enabled)->toBeTrue();
    expect($settings->enterpriseSsoCountsAsMfa)->toBeTrue();
});

it('parses explicit overrides from user_settings', function (): void {
    $settings = EnterpriseSsoSettings::fromUserSettings([
        'authentication_strategies' => [
            'enterprise_sso' => ['enabled' => false],
        ],
        'multi_factor' => [
            'enterprise_sso_counts_as_mfa' => false,
        ],
    ]);

    expect($settings->enabled)->toBeFalse();
    expect($settings->enterpriseSsoCountsAsMfa)->toBeFalse();
});

it('round-trips through toArray + fromUserSettings preserving every value', function (): void {
    $original = new EnterpriseSsoSettings(enabled: false, enterpriseSsoCountsAsMfa: false);
    $roundTripped = EnterpriseSsoSettings::fromUserSettings($original->toArray());

    expect($roundTripped->enabled)->toBeFalse();
    expect($roundTripped->enterpriseSsoCountsAsMfa)->toBeFalse();
});

it('tolerates partial or malformed settings shapes', function (): void {
    $settings = EnterpriseSsoSettings::fromUserSettings([
        'authentication_strategies' => 'not-an-array',
        'multi_factor' => ['enterprise_sso_counts_as_mfa' => false],
    ]);

    expect($settings->enabled)->toBeTrue();
    expect($settings->enterpriseSsoCountsAsMfa)->toBeFalse();
});
