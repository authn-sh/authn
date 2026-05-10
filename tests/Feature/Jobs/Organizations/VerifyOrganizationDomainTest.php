<?php

declare(strict_types=1);

use App\Events\Organizations\OrganizationDomainVerified;
use App\Jobs\Organizations\VerifyOrganizationDomain;
use App\Models\Challenge;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\Project;
use App\Models\Verification;
use App\Services\Domains\DnsTxtResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

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
    $challenge = Challenge::query()->withoutGlobalScopes()->create([
        'environment_id' => $env->id,
        'parent_type' => Challenge::PARENT_ORGANIZATION_DOMAIN,
        'parent_id' => $domain->id,
        'step' => Challenge::STEP_SINGLE,
        'strategy' => Verification::STRATEGY_DOMAIN_DNS_TXT,
        'status' => Challenge::STATUS_PENDING,
        'verification_id' => $verification->id,
        'attempts' => 0,
        'nonce' => $verification->nonce,
        'expire_at' => $verification->expire_at,
    ]);
    $domain->forceFill(['current_challenge_id' => $challenge->id])->save();

    return $domain->fresh();
}

function challengeFor(OrganizationDomain $domain): ?Challenge
{
    return Challenge::query()
        ->withoutGlobalScopes()
        ->where('parent_type', Challenge::PARENT_ORGANIZATION_DOMAIN)
        ->where('parent_id', $domain->id)
        ->latest('id')
        ->first();
}

function verificationForDomain(OrganizationDomain $domain): Verification
{
    return Verification::query()
        ->withoutGlobalScopes()
        ->where('verifiable_type', $domain->getMorphClass())
        ->where('verifiable_id', $domain->id)
        ->latest('id')
        ->firstOrFail();
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
    expect($fresh->current_challenge_id)->toBeNull();
    expect(verificationForDomain($fresh)->status)->toBe('verified');
    expect(challengeFor($fresh)->status)->toBe(Challenge::STATUS_VERIFIED);
    Event::assertDispatched(OrganizationDomainVerified::class, 1);
});

it('records a miss without flipping verified, increments attempts, re-enqueues', function (): void {
    Bus::fake([VerifyOrganizationDomain::class]);
    $f = bootDomainEnv();
    $domain = makeUnverifiedDomain($f['env']);

    $resolver = new FakeDnsTxtResolver;
    app()->instance(DnsTxtResolver::class, $resolver);

    (new VerifyOrganizationDomain($domain->id))->handle($resolver);

    $verification = verificationForDomain($domain);
    expect($verification->status)->toBe('unverified');
    expect((int) $verification->attempts)->toBe(1);
    expect($domain->fresh()->verified)->toBeFalse();
    expect((int) challengeFor($domain)->attempts)->toBe(1);
    Bus::assertDispatched(VerifyOrganizationDomain::class, 1);
});

it('demotes a previously-verified domain when the TXT record disappears', function (): void {
    Bus::fake([VerifyOrganizationDomain::class]);
    $f = bootDomainEnv();
    $domain = makeUnverifiedDomain($f['env']);
    // Simulate already verified — current_challenge_id cleared, challenge marked verified.
    $verification = verificationForDomain($domain);
    $verification->forceFill(['status' => 'verified', 'verified_at' => now()])->save();
    $challenge = challengeFor($domain);
    $challenge->forceFill(['status' => Challenge::STATUS_VERIFIED])->save();
    $domain->forceFill(['verified' => true, 'current_challenge_id' => null])->save();

    $resolver = new FakeDnsTxtResolver;
    app()->instance(DnsTxtResolver::class, $resolver);

    (new VerifyOrganizationDomain($domain->id))->handle($resolver);

    expect($domain->fresh()->verified)->toBeFalse();
});

it('stops re-enqueueing after MAX_ATTEMPTS', function (): void {
    Bus::fake([VerifyOrganizationDomain::class]);
    $f = bootDomainEnv();
    $domain = makeUnverifiedDomain($f['env']);
    verificationForDomain($domain)->forceFill(['attempts' => VerifyOrganizationDomain::MAX_ATTEMPTS - 1])->save();

    $resolver = new FakeDnsTxtResolver;
    app()->instance(DnsTxtResolver::class, $resolver);

    (new VerifyOrganizationDomain($domain->id))->handle($resolver);

    Bus::assertNotDispatched(VerifyOrganizationDomain::class);
});
