<?php

declare(strict_types=1);

namespace App\Events\Organizations;

use App\Models\OrganizationDomain;
use Illuminate\Foundation\Events\Dispatchable;

final class OrganizationDomainVerified
{
    use Dispatchable;

    public function __construct(public readonly OrganizationDomain $domain) {}
}
