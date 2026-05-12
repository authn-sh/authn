<?php

declare(strict_types=1);

namespace App\Auth\Saml;

use DateTimeImmutable;

/**
 * Value object for the subset of a verified SAML 2.0 assertion the rest of
 * the codebase actually needs. Built by `SamlConnectionService::parseSamlResponse`;
 * consumed by `EnterpriseSsoStrategy` (AU-7) to find-or-create the
 * `EnterpriseAccount` + `User`.
 */
final readonly class SamlAssertion
{
    /**
     * @param  array<string, list<string>>  $attributes  Multi-valued attribute statement, keyed by AttributeName.
     */
    public function __construct(
        public string $nameId,
        public ?string $nameIdFormat,
        public array $attributes,
        public ?string $sessionIndex,
        public ?DateTimeImmutable $notOnOrAfter,
        public ?DateTimeImmutable $authnInstant,
        public ?string $authnContextClassRef,
    ) {}

    public function firstAttribute(string $name): ?string
    {
        return $this->attributes[$name][0] ?? null;
    }
}
