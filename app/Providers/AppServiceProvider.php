<?php

namespace App\Providers;

use App\Events\Organizations\OrganizationCreated;
use App\Events\Organizations\OrganizationDeleted;
use App\Events\Organizations\OrganizationDomainCreated;
use App\Events\Organizations\OrganizationDomainDeleted;
use App\Events\Organizations\OrganizationDomainUpdated;
use App\Events\Organizations\OrganizationDomainVerified;
use App\Events\Organizations\OrganizationInvitationAccepted;
use App\Events\Organizations\OrganizationInvitationCreated;
use App\Events\Organizations\OrganizationInvitationRevoked;
use App\Events\Organizations\OrganizationMembershipCreated;
use App\Events\Organizations\OrganizationMembershipDeleted;
use App\Events\Organizations\OrganizationMembershipRequestApproved;
use App\Events\Organizations\OrganizationMembershipRequestCreated;
use App\Events\Organizations\OrganizationMembershipRequestRejected;
use App\Events\Organizations\OrganizationMembershipUpdated;
use App\Events\Organizations\OrganizationUpdated;
use App\Events\Organizations\RoleCreated;
use App\Events\Organizations\RoleDeleted;
use App\Events\Organizations\RolePermissionsChanged;
use App\Events\Organizations\RoleUpdated;
use App\Listeners\Organizations\OrganizationWebhookListener;
use App\Listeners\Organizations\SendOrganizationInvitationEmailListener;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\User;
use App\Observers\EnvironmentObserver;
use App\Services\Tenancy\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Environment::observe(EnvironmentObserver::class);

        // Policy-style entry points for org-scoped permission checks.
        // Controllers in AU-3+ can write
        //   $this->authorize('org:memberships:manage', $organization)
        // and the abilities listed below dispatch through to
        // User::hasOrgPermission().
        foreach (RoleSeeder::SYSTEM_PERMISSIONS as $row) {
            Gate::define(
                $row['key'],
                fn (User $user, Organization $org): bool => $user->hasOrgPermission($row['key'], $org),
            );
        }

        // AU-11: route every org-domain Eloquent event through the
        // Webhooks\Emitter via OrganizationWebhookListener. Pairs map the
        // event class to the listener method name (Laravel resolves the
        // listener instance from the container).
        $listener = OrganizationWebhookListener::class;
        $eventMap = [
            OrganizationCreated::class => 'organizationCreated',
            OrganizationUpdated::class => 'organizationUpdated',
            OrganizationDeleted::class => 'organizationDeleted',
            OrganizationMembershipCreated::class => 'membershipCreated',
            OrganizationMembershipUpdated::class => 'membershipUpdated',
            OrganizationMembershipDeleted::class => 'membershipDeleted',
            OrganizationInvitationCreated::class => 'invitationCreated',
            OrganizationInvitationAccepted::class => 'invitationAccepted',
            OrganizationInvitationRevoked::class => 'invitationRevoked',
            OrganizationDomainCreated::class => 'domainCreated',
            OrganizationDomainUpdated::class => 'domainUpdated',
            OrganizationDomainDeleted::class => 'domainDeleted',
            OrganizationDomainVerified::class => 'domainVerified',
            OrganizationMembershipRequestCreated::class => 'membershipRequestCreated',
            OrganizationMembershipRequestApproved::class => 'membershipRequestApproved',
            OrganizationMembershipRequestRejected::class => 'membershipRequestRejected',
            RoleCreated::class => 'roleCreated',
            RoleUpdated::class => 'roleUpdated',
            RoleDeleted::class => 'roleDeleted',
            RolePermissionsChanged::class => 'rolePermissionsChanged',
        ];
        foreach ($eventMap as $event => $method) {
            Event::listen($event, "{$listener}@{$method}");
        }

        // AU-14: dispatch the org-invitation email when an invitation is
        // minted. Separate listener from the webhook one so they retry
        // independently on failure.
        Event::listen(
            OrganizationInvitationCreated::class,
            SendOrganizationInvitationEmailListener::class,
        );
    }
}
