<?php

declare(strict_types=1);

namespace App\Events\Organizations;

use App\Models\OrganizationInvitation;
use Illuminate\Foundation\Events\Dispatchable;

final class OrganizationInvitationRevoked
{
    use Dispatchable;

    public function __construct(public readonly OrganizationInvitation $invitation) {}
}
