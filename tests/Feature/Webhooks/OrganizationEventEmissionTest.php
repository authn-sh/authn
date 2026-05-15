<?php

declare(strict_types=1);

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
use App\Jobs\Webhooks\DispatchWebhookDelivery;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\OrganizationMembershipRequest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Support\Facades\Bus;

function bootOrgEventsEnv(): array
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-events']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'events',
        'routing_label' => 'events',
        'allowed_origins' => [],
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['env' => $env];
}

function makeOrgEventsEndpoint(Environment $env, array $types = ['*']): WebhookEndpoint
{
    return WebhookEndpoint::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'url' => 'https://example.test/hook',
        'signing_secret' => WebhookEndpoint::mintSecret(),
        'enabled_event_types' => $types,
        'enabled' => true,
    ]);
}

function makeOrgEventsOrg(Environment $env, string $slug = 'acme'): Organization
{
    return Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => $slug]);
}

function latestOrgWebhookEvent(string $type): ?WebhookEvent
{
    return WebhookEvent::query()->where('type', $type)->latest('id')->first();
}

it('emits organization.created/.updated/.deleted', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = bootOrgEventsEnv();
    makeOrgEventsEndpoint($f['env']);
    $org = makeOrgEventsOrg($f['env']);

    OrganizationCreated::dispatch($org);
    expect(latestOrgWebhookEvent('organization.created')?->data['id'])->toBe($org->id);

    OrganizationUpdated::dispatch($org);
    expect(latestOrgWebhookEvent('organization.updated'))->not->toBeNull();

    OrganizationDeleted::dispatch($org);
    expect(latestOrgWebhookEvent('organization.deleted'))->not->toBeNull();
});

it('emits organizationMembership.created/.updated/.deleted with public_user_data', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = bootOrgEventsEnv();
    makeOrgEventsEndpoint($f['env']);
    $org = makeOrgEventsOrg($f['env']);
    $user = new User(['environment_id' => $f['env']->id, 'username' => 'm']);
    $user->save();
    $admin = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:admin')->firstOrFail();
    $m = OrganizationMembership::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $admin->id,
    ]);

    OrganizationMembershipCreated::dispatch($m);
    $event = latestOrgWebhookEvent('organizationMembership.created');
    expect($event)->not->toBeNull();
    expect($event->data['public_user_data']['user_id'])->toBe($user->id);

    OrganizationMembershipUpdated::dispatch($m);
    expect(latestOrgWebhookEvent('organizationMembership.updated'))->not->toBeNull();

    OrganizationMembershipDeleted::dispatch($m);
    expect(latestOrgWebhookEvent('organizationMembership.deleted'))->not->toBeNull();
});

it('emits organizationInvitation.created with url, plus accepted/revoked', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = bootOrgEventsEnv();
    makeOrgEventsEndpoint($f['env']);
    $org = makeOrgEventsOrg($f['env']);
    $role = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:member')->firstOrFail();
    $inv = OrganizationInvitation::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'email_address' => 'a@example.com',
        'role_id' => $role->id,
        'status' => 'pending',
    ]);

    OrganizationInvitationCreated::dispatch($inv, 'https://app.example.com/sign-up?__authn_ticket=fixture');
    $event = latestOrgWebhookEvent('organizationInvitation.created');
    expect($event)->not->toBeNull();
    expect($event->data['url'])->toBe('https://app.example.com/sign-up?__authn_ticket=fixture');

    OrganizationInvitationAccepted::dispatch($inv);
    expect(latestOrgWebhookEvent('organizationInvitation.accepted'))->not->toBeNull();

    OrganizationInvitationRevoked::dispatch($inv);
    expect(latestOrgWebhookEvent('organizationInvitation.revoked'))->not->toBeNull();
});

it('emits organizationDomain.created/updated/deleted/verified', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = bootOrgEventsEnv();
    makeOrgEventsEndpoint($f['env']);
    $org = makeOrgEventsOrg($f['env']);
    $domain = OrganizationDomain::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'name' => 'acme.test',
    ]);

    OrganizationDomainCreated::dispatch($domain);
    expect(latestOrgWebhookEvent('organizationDomain.created'))->not->toBeNull();
    OrganizationDomainUpdated::dispatch($domain);
    expect(latestOrgWebhookEvent('organizationDomain.updated'))->not->toBeNull();
    OrganizationDomainVerified::dispatch($domain);
    expect(latestOrgWebhookEvent('organizationDomain.verified'))->not->toBeNull();
    OrganizationDomainDeleted::dispatch($domain);
    expect(latestOrgWebhookEvent('organizationDomain.deleted'))->not->toBeNull();
});

it('emits organizationMembershipRequest.created/accepted/rejected', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = bootOrgEventsEnv();
    makeOrgEventsEndpoint($f['env']);
    $org = makeOrgEventsOrg($f['env']);
    $user = new User(['environment_id' => $f['env']->id, 'username' => 'r']);
    $user->save();
    $req = OrganizationMembershipRequest::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => 'pending',
    ]);

    OrganizationMembershipRequestCreated::dispatch($req);
    expect(latestOrgWebhookEvent('organizationMembershipRequest.created'))->not->toBeNull();
    OrganizationMembershipRequestApproved::dispatch($req);
    expect(latestOrgWebhookEvent('organizationMembershipRequest.accepted'))->not->toBeNull();
    OrganizationMembershipRequestRejected::dispatch($req);
    expect(latestOrgWebhookEvent('organizationMembershipRequest.rejected'))->not->toBeNull();
});

it('emits role.created/updated/deleted; RolePermissionsChanged maps to role.updated', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = bootOrgEventsEnv();
    makeOrgEventsEndpoint($f['env']);

    $role = Role::create(['environment_id' => $f['env']->id, 'key' => 'org:custom', 'name' => 'C']);

    RoleCreated::dispatch($role);
    expect(latestOrgWebhookEvent('role.created'))->not->toBeNull();

    RoleUpdated::dispatch($role);
    expect(latestOrgWebhookEvent('role.updated'))->not->toBeNull();

    $perm = Permission::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:profile:read')->firstOrFail();
    $role->permissions()->attach($perm->id);
    RolePermissionsChanged::dispatch($role->fresh(), [$perm->key]);
    // RolePermissionsChanged maps to role.updated per AU-11 spec.
    expect(WebhookEvent::query()->where('type', 'role.updated')->count())->toBeGreaterThanOrEqual(2);

    RoleDeleted::dispatch($role);
    expect(latestOrgWebhookEvent('role.deleted'))->not->toBeNull();
});

it('matches `organization.*` glob pattern on enabled_event_types', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = bootOrgEventsEnv();
    $endpoint = makeOrgEventsEndpoint($f['env'], types: ['organization.*']);
    $org = makeOrgEventsOrg($f['env']);

    OrganizationCreated::dispatch($org);
    OrganizationUpdated::dispatch($org);

    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->count())->toBe(2);
});

it('filters by literal exact match', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = bootOrgEventsEnv();
    $endpoint = makeOrgEventsEndpoint($f['env'], types: ['organization.created']);
    $org = makeOrgEventsOrg($f['env']);

    OrganizationCreated::dispatch($org);
    OrganizationUpdated::dispatch($org);
    OrganizationDeleted::dispatch($org);

    // Only organization.created subscribed.
    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->count())->toBe(1);
});

it('does NOT match unrelated prefix when listed event has different namespace', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = bootOrgEventsEnv();
    $endpoint = makeOrgEventsEndpoint($f['env'], types: ['role.*']);
    $org = makeOrgEventsOrg($f['env']);

    OrganizationCreated::dispatch($org);
    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->count())->toBe(0);

    $role = Role::create(['environment_id' => $f['env']->id, 'key' => 'org:custom2', 'name' => 'X']);
    RoleCreated::dispatch($role);
    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->count())->toBe(1);
});
