<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationInvitationCreated;
use App\Events\Organizations\OrganizationMembershipRequestCreated;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\OrganizationMembershipRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Domains\DomainEnroller;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function makeEnrollerEnv(string $slug = 'enroller'): array
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
        'allowed_origins' => [],
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['env' => $env];
}

function makeDomain(Environment $env, string $name, string $mode, bool $verified = true): OrganizationDomain
{
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme '.$name, 'slug' => 'acme-'.str_replace('.', '-', $name)]);

    return OrganizationDomain::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'name' => $name,
        'enrollment_mode' => $mode,
        'verified' => $verified,
    ]);
}

function makeUserWithEmail(Environment $env, string $email): array
{
    $user = new User(['environment_id' => $env->id, 'username' => substr($email, 0, strpos($email, '@'))]);
    $user->save();
    $row = EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => $email,
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    return ['user' => $user, 'email' => $row];
}

it('manual_invitation domains do nothing on sign-up', function (): void {
    Event::fake([OrganizationInvitationCreated::class, OrganizationMembershipRequestCreated::class]);
    $f = makeEnrollerEnv();
    makeDomain($f['env'], 'acme.test', OrganizationDomain::MODE_MANUAL_INVITATION);
    $u = makeUserWithEmail($f['env'], 'newbie@acme.test');

    app(DomainEnroller::class)->enroll($f['env'], $u['email']);

    expect(OrganizationInvitation::query()->count())->toBe(0);
    Event::assertNotDispatched(OrganizationInvitationCreated::class);
    Event::assertNotDispatched(OrganizationMembershipRequestCreated::class);
});

it('automatic_invitation domains create a pending invitation in the env-default role', function (): void {
    Event::fake([OrganizationInvitationCreated::class]);
    $f = makeEnrollerEnv();
    $domain = makeDomain($f['env'], 'auto.test', OrganizationDomain::MODE_AUTOMATIC_INVITATION);
    $u = makeUserWithEmail($f['env'], 'newbie@auto.test');

    app(DomainEnroller::class)->enroll($f['env'], $u['email']);

    $invitation = OrganizationInvitation::query()->where('organization_id', $domain->organization_id)->firstOrFail();
    expect($invitation->email_address)->toBe('newbie@auto.test');
    expect($invitation->status)->toBe('pending');

    $defaultRole = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('is_default', true)->firstOrFail();
    expect($invitation->role_id)->toBe($defaultRole->id);

    expect((int) $domain->fresh()->total_pending_invitations)->toBe(1);
    expect((int) Organization::query()->withoutGlobalScopes()->where('id', $domain->organization_id)->firstOrFail()->pending_invitations_count)->toBe(1);

    Event::assertDispatched(OrganizationInvitationCreated::class, 1);
});

it('automatic_suggestion domains create a pending OrganizationMembershipRequest', function (): void {
    Event::fake([OrganizationMembershipRequestCreated::class]);
    $f = makeEnrollerEnv();
    $domain = makeDomain($f['env'], 'sug.test', OrganizationDomain::MODE_AUTOMATIC_SUGGESTION);
    $u = makeUserWithEmail($f['env'], 'newbie@sug.test');

    app(DomainEnroller::class)->enroll($f['env'], $u['email']);

    $req = OrganizationMembershipRequest::query()->where('organization_id', $domain->organization_id)->firstOrFail();
    expect($req->user_id)->toBe($u['user']->id);
    expect($req->status)->toBe('pending');
    expect((int) $domain->fresh()->total_pending_suggestions)->toBe(1);
    Event::assertDispatched(OrganizationMembershipRequestCreated::class, 1);
});

it('respects max_allowed_memberships and skips auto-invite when at the cap', function (): void {
    Event::fake([OrganizationInvitationCreated::class]);
    $f = makeEnrollerEnv();
    $domain = makeDomain($f['env'], 'cap.test', OrganizationDomain::MODE_AUTOMATIC_INVITATION);
    Organization::query()->withoutGlobalScopes()->where('id', $domain->organization_id)->update([
        'max_allowed_memberships' => 1,
        'members_count' => 1,
    ]);
    $u = makeUserWithEmail($f['env'], 'newbie@cap.test');

    app(DomainEnroller::class)->enroll($f['env'], $u['email']);

    expect(OrganizationInvitation::query()->where('organization_id', $domain->organization_id)->count())->toBe(0);
});

it('multi-domain match — each matching domain produces its own row', function (): void {
    Event::fake([OrganizationInvitationCreated::class, OrganizationMembershipRequestCreated::class]);
    $f = makeEnrollerEnv();
    $a = makeDomain($f['env'], 'multi.test', OrganizationDomain::MODE_AUTOMATIC_INVITATION);
    $b = makeDomain($f['env'], 'multi.test2', OrganizationDomain::MODE_AUTOMATIC_INVITATION);
    // Different host — should NOT match the email.
    makeDomain($f['env'], 'unrelated.test', OrganizationDomain::MODE_AUTOMATIC_INVITATION);

    $u = makeUserWithEmail($f['env'], 'newbie@multi.test');

    app(DomainEnroller::class)->enroll($f['env'], $u['email']);

    expect(OrganizationInvitation::query()->where('organization_id', $a->organization_id)->count())->toBe(1);
    expect(OrganizationInvitation::query()->where('organization_id', $b->organization_id)->count())->toBe(0);
    Event::assertDispatched(OrganizationInvitationCreated::class, 1);
});

it('skips when an unverified domain shares the email host', function (): void {
    Event::fake([OrganizationInvitationCreated::class]);
    $f = makeEnrollerEnv();
    makeDomain($f['env'], 'unverified.test', OrganizationDomain::MODE_AUTOMATIC_INVITATION, verified: false);
    $u = makeUserWithEmail($f['env'], 'newbie@unverified.test');

    app(DomainEnroller::class)->enroll($f['env'], $u['email']);

    expect(OrganizationInvitation::query()->count())->toBe(0);
});

it('does not create a duplicate invitation when one already pending', function (): void {
    Event::fake([OrganizationInvitationCreated::class]);
    $f = makeEnrollerEnv();
    $domain = makeDomain($f['env'], 'dup.test', OrganizationDomain::MODE_AUTOMATIC_INVITATION);
    $defaultRole = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('is_default', true)->firstOrFail();
    OrganizationInvitation::query()->withoutGlobalScopes()->create([
        'environment_id' => $f['env']->id,
        'organization_id' => $domain->organization_id,
        'email_address' => 'newbie@dup.test',
        'role_id' => $defaultRole->id,
        'status' => 'pending',
    ]);

    $u = makeUserWithEmail($f['env'], 'newbie@dup.test');
    app(DomainEnroller::class)->enroll($f['env'], $u['email']);

    expect(OrganizationInvitation::query()->where('organization_id', $domain->organization_id)->count())->toBe(1);
    Event::assertNotDispatched(OrganizationInvitationCreated::class);
});

it('skips suggestion when user is already a member', function (): void {
    Event::fake([OrganizationMembershipRequestCreated::class]);
    $f = makeEnrollerEnv();
    $domain = makeDomain($f['env'], 'already.test', OrganizationDomain::MODE_AUTOMATIC_SUGGESTION);
    $u = makeUserWithEmail($f['env'], 'newbie@already.test');
    $defaultRole = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('is_default', true)->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $domain->organization_id,
        'user_id' => $u['user']->id,
        'role_id' => $defaultRole->id,
    ]);

    app(DomainEnroller::class)->enroll($f['env'], $u['email']);

    expect(OrganizationMembershipRequest::query()->count())->toBe(0);
    Event::assertNotDispatched(OrganizationMembershipRequestCreated::class);
});
