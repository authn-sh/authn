<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationDomainVerified;
use App\Jobs\Organizations\VerifyOrganizationDomain;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\Project;
use App\Models\Verification;
use App\Services\Domains\DnsTxtResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

class FakeDnsTxtResolver extends DnsTxtResolver
{
    /** @var array<string, list<string>> */
    public array $records = [];

    public function resolve(string $host): array
    {
        return $this->records[$host] ?? [];
    }
}

function bootDomainEnv(): array
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-domain']);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'acme',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    return ['env' => $env];
}

function makeUnverifiedDomain(Environment $env, string $name = 'acme.test', string $mode = 'manual_invitation'): OrganizationDomain
{
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme-'.$name]);
    $domain = OrganizationDomain::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'name' => $name,
        'enrollment_mode' => $mode,
    ]);
    $verification = Verification::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'verifiable_type' => $domain->getMorphClass(),
        'verifiable_id' => $domain->id,
        'strategy' => Verification::STRATEGY_DOMAIN_DNS_TXT,
        'status' => Verification::STATUS_UNVERIFIED,
        'attempts' => 0,
        'expire_at' => now()->addDays(14),
        'nonce' => 'authn-domain-verify=fixture-nonce-1234',
    ]);
    $domain->forceFill(['verification_id' => $verification->id])->save();

    return $domain->fresh();
}

it('flips a domain to verified when the TXT record matches and fires OrganizationDomainVerified', function (): void {
    Event::fake([OrganizationDomainVerified::class]);
    $f = bootDomainEnv();
    $domain = makeUnverifiedDomain($f['env']);

    $resolver = new FakeDnsTxtResolver;
    $resolver->records['_authn-verification.acme.test'] = ['authn-domain-verify=fixture-nonce-1234'];
    app()->instance(DnsTxtResolver::class, $resolver);

    (new VerifyOrganizationDomain($domain->id))->handle($resolver);

    $fresh = $domain->fresh();
    expect($fresh->verified)->toBeTrue();
    $verification = Verification::query()->withoutGlobalScopes()->where('id', $fresh->verification_id)->firstOrFail();
    expect($verification->status)->toBe('verified');
    Event::assertDispatched(OrganizationDomainVerified::class, 1);
});

it('records a miss without flipping verified, increments attempts, re-enqueues', function (): void {
    Bus::fake([VerifyOrganizationDomain::class]);
    $f = bootDomainEnv();
    $domain = makeUnverifiedDomain($f['env']);

    $resolver = new FakeDnsTxtResolver;
    // No matching TXT record present.
    app()->instance(DnsTxtResolver::class, $resolver);

    (new VerifyOrganizationDomain($domain->id))->handle($resolver);

    $verification = Verification::query()->withoutGlobalScopes()->where('id', $domain->verification_id)->firstOrFail();
    expect($verification->status)->toBe('unverified');
    expect((int) $verification->attempts)->toBe(1);
    expect($domain->fresh()->verified)->toBeFalse();
    Bus::assertDispatched(VerifyOrganizationDomain::class, 1);
});

it('demotes a previously-verified domain when the TXT record disappears', function (): void {
    Bus::fake([VerifyOrganizationDomain::class]);
    $f = bootDomainEnv();
    $domain = makeUnverifiedDomain($f['env']);
    // Simulate already verified.
    $domain->forceFill(['verified' => true])->save();
    Verification::query()->withoutGlobalScopes()->where('id', $domain->verification_id)->update([
        'status' => 'verified',
        'verified_at' => now(),
    ]);

    $resolver = new FakeDnsTxtResolver;
    // Record gone.
    app()->instance(DnsTxtResolver::class, $resolver);

    (new VerifyOrganizationDomain($domain->id))->handle($resolver);

    expect($domain->fresh()->verified)->toBeFalse();
});

it('stops re-enqueueing after MAX_ATTEMPTS', function (): void {
    Bus::fake([VerifyOrganizationDomain::class]);
    $f = bootDomainEnv();
    $domain = makeUnverifiedDomain($f['env']);
    Verification::query()->withoutGlobalScopes()->where('id', $domain->verification_id)->update([
        'attempts' => VerifyOrganizationDomain::MAX_ATTEMPTS - 1,
    ]);

    $resolver = new FakeDnsTxtResolver;
    app()->instance(DnsTxtResolver::class, $resolver);

    (new VerifyOrganizationDomain($domain->id))->handle($resolver);

    Bus::assertNotDispatched(VerifyOrganizationDomain::class);
});
