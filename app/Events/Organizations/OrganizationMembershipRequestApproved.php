<?php

declare(strict_types=1);

namespace App\Events\Organizations;

use App\Models\OrganizationMembershipRequest;
use Illuminate\Foundation\Events\Dispatchable;

final class OrganizationMembershipRequestApproved
{
    use Dispatchable;

    public function __construct(public readonly OrganizationMembershipRequest $request) {}
}
