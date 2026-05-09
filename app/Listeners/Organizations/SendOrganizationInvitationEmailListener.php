<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

use App\Events\Organizations\OrganizationInvitationCreated;
use App\Jobs\Mail\SendOrganizationInvitationEmail;

/**
 * Drops the org-invitation email job onto the mail queue when an
 * OrganizationInvitationCreated event fires (AU-14). Distinct from
 * OrganizationWebhookListener (AU-11) — webhooks notify customers,
 * this one notifies the invitee.
 */
final class SendOrganizationInvitationEmailListener
{
    public function handle(OrganizationInvitationCreated $event): void
    {
        SendOrganizationInvitationEmail::dispatch(
            $event->invitation->id,
            $event->url,
        );
    }
}
