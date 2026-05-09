<?php

declare(strict_types=1);

namespace App\Events\Organizations;

use App\Models\OrganizationMembership;
use Illuminate\Foundation\Events\Dispatchable;

final class OrganizationMembershipCreated
{
    use Dispatchable;

    public function __construct(public readonly OrganizationMembership $membership) {}
}
