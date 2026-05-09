<?php

declare(strict_types=1);

namespace App\Events\Organizations;

use App\Models\OrganizationInvitation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries both the invitation row and the freshly-minted ticket URL so
 * the email worker (AU-14 v0.2) can render the message without re-issuing
 * the JWT.
 */
final class OrganizationInvitationCreated
{
    use Dispatchable;

    public function __construct(
        public readonly OrganizationInvitation $invitation,
        public readonly string $url,
    ) {}
}
