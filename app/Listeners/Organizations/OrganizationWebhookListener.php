<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

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
use App\Http\Resources\OrganizationDomainResource;
use App\Http\Resources\OrganizationInvitationResource;
use App\Http\Resources\OrganizationMembershipRequestResource;
use App\Http\Resources\OrganizationMembershipResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\RoleResource;
use App\Models\Environment;
use App\Models\Organization;
use App\Webhooks\Emitter;

/**
 * Maps every Eloquent organization-domain event from AU-3 / AU-4 / AU-5 /
 * AU-7 / AU-9 to a `WebhookEvent` row of the documented type and queues
 * deliveries through the existing v0.1 dispatcher.
 *
 * One method per event class so Laravel's auto-binding of `@param`
 * type-hinted listeners works without dispatcher glue. Listener
 * registration happens in AppServiceProvider::boot().
 */
final class OrganizationWebhookListener
{
    public function __construct(private readonly Emitter $emitter) {}

    public function organizationCreated(OrganizationCreated $event): void
    {
        $this->emit('organization.created', OrganizationResource::from($event->organization, includePrivate: true), $event->organization);
    }

    public function organizationUpdated(OrganizationUpdated $event): void
    {
        $this->emit('organization.updated', OrganizationResource::from($event->organization, includePrivate: true), $event->organization);
    }

    public function organizationDeleted(OrganizationDeleted $event): void
    {
        $this->emit('organization.deleted', OrganizationResource::from($event->organization, includePrivate: true), $event->organization);
    }

    public function membershipCreated(OrganizationMembershipCreated $event): void
    {
        $org = $this->loadOrgFor($event->membership->organization_id);
        $this->emit('organizationMembership.created', OrganizationMembershipResource::from($event->membership->load(['user', 'role.permissions']), includePrivate: true), $org);
    }

    public function membershipUpdated(OrganizationMembershipUpdated $event): void
    {
        $org = $this->loadOrgFor($event->membership->organization_id);
        $this->emit('organizationMembership.updated', OrganizationMembershipResource::from($event->membership->load(['user', 'role.permissions']), includePrivate: true), $org);
    }

    public function membershipDeleted(OrganizationMembershipDeleted $event): void
    {
        $org = $this->loadOrgFor($event->membership->organization_id);
        $this->emit('organizationMembership.deleted', OrganizationMembershipResource::from($event->membership->load(['user', 'role.permissions']), includePrivate: true), $org);
    }

    public function invitationCreated(OrganizationInvitationCreated $event): void
    {
        $org = $this->loadOrgFor($event->invitation->organization_id);
        $this->emit('organizationInvitation.created', OrganizationInvitationResource::from($event->invitation->load('role'), $event->url), $org);
    }

    public function invitationAccepted(OrganizationInvitationAccepted $event): void
    {
        $org = $this->loadOrgFor($event->invitation->organization_id);
        $this->emit('organizationInvitation.accepted', OrganizationInvitationResource::from($event->invitation->load('role')), $org);
    }

    public function invitationRevoked(OrganizationInvitationRevoked $event): void
    {
        $org = $this->loadOrgFor($event->invitation->organization_id);
        $this->emit('organizationInvitation.revoked', OrganizationInvitationResource::from($event->invitation->load('role')), $org);
    }

    public function domainCreated(OrganizationDomainCreated $event): void
    {
        $org = $this->loadOrgFor($event->domain->organization_id);
        $this->emit('organizationDomain.created', OrganizationDomainResource::from($event->domain), $org);
    }

    public function domainUpdated(OrganizationDomainUpdated $event): void
    {
        $org = $this->loadOrgFor($event->domain->organization_id);
        $this->emit('organizationDomain.updated', OrganizationDomainResource::from($event->domain), $org);
    }

    public function domainDeleted(OrganizationDomainDeleted $event): void
    {
        $org = $this->loadOrgFor($event->domain->organization_id);
        $this->emit('organizationDomain.deleted', OrganizationDomainResource::from($event->domain), $org);
    }

    public function domainVerified(OrganizationDomainVerified $event): void
    {
        $org = $this->loadOrgFor($event->domain->organization_id);
        $this->emit('organizationDomain.verified', OrganizationDomainResource::from($event->domain), $org);
    }

    public function membershipRequestCreated(OrganizationMembershipRequestCreated $event): void
    {
        $org = $this->loadOrgFor($event->request->organization_id);
        $this->emit('organizationMembershipRequest.created', OrganizationMembershipRequestResource::from($event->request), $org);
    }

    public function membershipRequestApproved(OrganizationMembershipRequestApproved $event): void
    {
        $org = $this->loadOrgFor($event->request->organization_id);
        $this->emit('organizationMembershipRequest.accepted', OrganizationMembershipRequestResource::from($event->request), $org);
    }

    public function membershipRequestRejected(OrganizationMembershipRequestRejected $event): void
    {
        $org = $this->loadOrgFor($event->request->organization_id);
        $this->emit('organizationMembershipRequest.rejected', OrganizationMembershipRequestResource::from($event->request), $org);
    }

    public function roleCreated(RoleCreated $event): void
    {
        $env = $this->loadEnv($event->role->environment_id);
        $this->emitter->emit('role.created', RoleResource::from($event->role->load('permissions')), $env);
    }

    public function roleUpdated(RoleUpdated $event): void
    {
        $env = $this->loadEnv($event->role->environment_id);
        $this->emitter->emit('role.updated', RoleResource::from($event->role->load('permissions')), $env);
    }

    public function roleDeleted(RoleDeleted $event): void
    {
        $env = $this->loadEnv($event->role->environment_id);
        $this->emitter->emit('role.deleted', RoleResource::from($event->role->load('permissions')), $env);
    }

    public function rolePermissionsChanged(RolePermissionsChanged $event): void
    {
        $env = $this->loadEnv($event->role->environment_id);
        $this->emitter->emit('role.updated', RoleResource::from($event->role->load('permissions')), $env);
    }

    /**
     * Send the event through Emitter scoped to the org's Environment.
     *
     * @param  array<string, mixed>  $data
     */
    private function emit(string $type, array $data, ?Organization $org): void
    {
        if ($org === null) {
            return;
        }
        $env = $this->loadEnv($org->environment_id);
        $this->emitter->emit($type, $data, $env);
    }

    private function loadOrgFor(?string $organizationId): ?Organization
    {
        if (! is_string($organizationId) || $organizationId === '') {
            return null;
        }

        return Organization::query()->withoutGlobalScopes()->where('id', $organizationId)->first();
    }

    private function loadEnv(?string $environmentId): ?Environment
    {
        if (! is_string($environmentId) || $environmentId === '') {
            return null;
        }

        return Environment::query()->withoutGlobalScopes()->where('id', $environmentId)->first();
    }
}
