<?php

declare(strict_types=1);

namespace App\Events\Organizations;

use App\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Plain event hook so AU-11 can register a webhook-emission listener
 * without further controller changes. Carries the Organization model;
 * listeners are responsible for shaping the resource and calling Emitter.
 */
final class OrganizationCreated
{
    use Dispatchable;

    public function __construct(public readonly Organization $organization) {}
}
