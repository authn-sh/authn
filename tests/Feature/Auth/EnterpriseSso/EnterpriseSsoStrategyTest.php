<?php

declare(strict_types=1);

use App\Auth\Strategies\EnterpriseSsoStrategy;
use App\Auth\StrategyResolver;
use App\Models\Verification;

it('registers the enterprise_sso + saml strategies on the resolver', function (): void {
    $resolver = app(StrategyResolver::class);
    expect($resolver->resolve(Verification::STRATEGY_ENTERPRISE_SSO))->toBeInstanceOf(EnterpriseSsoStrategy::class);
    expect($resolver->resolve(Verification::STRATEGY_SAML))->toBeInstanceOf(EnterpriseSsoStrategy::class);
});

it('declares both strategies as requiring a prepare step', function (): void {
    $strategy = app(EnterpriseSsoStrategy::class);
    expect($strategy->requiresPrepare())->toBeTrue();
    expect($strategy->name())->toBe('enterprise_sso');
});
