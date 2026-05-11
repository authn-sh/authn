<?php

declare(strict_types=1);

namespace App\Scim;

/**
 * Canonical SCIM 2.0 → internal User attribute defaults. `ScimAttributeMapping`
 * rows override these per-org; the merge happens at read time so we can ship
 * new defaults without backfill.
 */
final class DefaultAttributeMappings
{
    /**
     * Map of SCIM source attribute path → internal User column.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'userName' => 'email_address',
        'name.givenName' => 'first_name',
        'name.familyName' => 'last_name',
        'emails[primary eq true].value' => 'email_address',
        'externalId' => 'external_id',
        'displayName' => 'username',
        'active' => 'banned',
        'locale' => 'locale',
    ];
}
