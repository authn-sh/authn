<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationInvitationCreated;
use App\Jobs\Mail\SendOrganizationInvitationEmail;
use App\Mail\EmailPipeline;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\Role;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Support\Facades\Bus;


function bootOrgEmailEnv(): array
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-org-email']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'org-email',
        'routing_label' => 'org-email',
        'allowed_origins' => [],
    ]);
    (new SigningKeyGenerator)->generate($env);

    return ['env' => $env];
}

it('seeds magic_link_sign_in / magic_link_sign_up / organization_invitation templates on env create', function (): void {
    $f = bootOrgEmailEnv();

    foreach ([
        EmailTemplate::SLUG_MAGIC_LINK_SIGN_IN,
        EmailTemplate::SLUG_MAGIC_LINK_SIGN_UP,
        EmailTemplate::SLUG_ORGANIZATION_INVITATION,
    ] as $slug) {
        $row = EmailTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $f['env']->id)
            ->where('slug', $slug)
            ->first();
        expect($row)->not->toBeNull();
        expect($row->subject)->not->toBe('');
        expect($row->body_markup)->toContain('<mjml>');
        expect($row->body_html)->toContain('<!doctype html>');
    }
});

it('magic_link_sign_in template renders the action_url + expires copy', function (): void {
    $f = bootOrgEmailEnv();
    $tmpl = EmailTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('slug', EmailTemplate::SLUG_MAGIC_LINK_SIGN_IN)
        ->firstOrFail();
    expect($tmpl->subject)->toContain('{{app.name}}');
    expect($tmpl->body_markup)->toContain('{{action_url}}')
        ->toContain('{{expires_at_human}}');
    expect($tmpl->body_html)->toContain('{{action_url}}');
});

it('organization_invitation template renders inviter / organization / role / action_url', function (): void {
    $f = bootOrgEmailEnv();
    $tmpl = EmailTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('slug', EmailTemplate::SLUG_ORGANIZATION_INVITATION)
        ->firstOrFail();
    expect($tmpl->subject)->toContain('{{organization.name}}');
    expect($tmpl->body_markup)
        ->toContain('{{inviter.name}}')
        ->toContain('{{organization.name}}')
        ->toContain('{{role.name}}')
        ->toContain('{{action_url}}');
});

it('OrganizationInvitationCreated event dispatches SendOrganizationInvitationEmail', function (): void {
    Bus::fake([SendOrganizationInvitationEmail::class]);
    $f = bootOrgEmailEnv();
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme']);
    $role = Role::query()->withoutGlobalScopes()
        ->where('environment_id', $f['env']->id)
        ->where('key', 'org:member')
        ->firstOrFail();
    $invitation = OrganizationInvitation::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'email_address' => 'invitee@example.com',
        'role_id' => $role->id,
        'status' => 'pending',
    ]);

    OrganizationInvitationCreated::dispatch($invitation, 'https://app.example.com/sign-up?__authn_ticket=abc');

    Bus::assertDispatched(
        SendOrganizationInvitationEmail::class,
        fn ($job) => $job->invitationId === $invitation->id && str_contains($job->url, '__authn_ticket=abc')
    );
});

it('SendOrganizationInvitationEmail::handle no-ops when the invitation row was deleted', function (): void {
    $f = bootOrgEmailEnv();
    $job = new SendOrganizationInvitationEmail('orginv_'.str_repeat('A', 26), 'https://app.example.com/sign-up');

    // Real EmailPipeline binding is fine — the job exits before touching it.
    $job->handle(app(EmailPipeline::class));

    expect(true)->toBeTrue();
});
