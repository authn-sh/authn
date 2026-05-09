<?php

declare(strict_types=1);

namespace App\Events\Organizations;

use App\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;

final class OrganizationUpdated
{
    use Dispatchable;

    public function __construct(public readonly Organization $organization) {}
}
