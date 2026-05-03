<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\SigningKey;
use App\Models\User;
use App\Services\Tenancy\BootstrapService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bootstrapWith(array $overrides = []): array
{
    $service = app(BootstrapService::class);

    $config = array_merge([
        'admin_email' => 'op@example.com',
        'admin_password' => 'super-secret-password',
        'workspace_name' => 'Acme Inc',
        'app_url' => 'https://authn.local',
    ], $overrides);

    $result = $service->run($config);

    expect($result)->not->toBeNull();

    return $result;
}

it('provisions the _admin project, environment, workspace org, operator, signing key, and api keys', function (): void {
    $result = bootstrapWith();

    /** @var Project $project */
    $project = $result['project'];
    expect($project->slug)->toBe(Project::SYSTEM_SLUG);
    expect($project->is_system)->toBeTrue();
    expect($project->id)->toStartWith('prj_');

    expect($result['environment']->id)->toStartWith('env_');
    expect($result['environment']->kind)->toBe('production');
    expect($result['environment']->frontend_api_host)->toBe('authn.local');

    /** @var Organization $workspace */
    $workspace = $result['workspace'];
    expect($workspace->name)->toBe('Acme Inc');
    expect($workspace->slug)->toBe('acme-inc');
    expect($workspace->created_by_user_id)->toBe($result['operator']->id);

    /** @var User $operator */
    $operator = $result['operator'];
    expect($operator->email)->toBe('op@example.com');
    expect($operator->checkPassword('super-secret-password'))->toBeTrue();
    expect($operator->checkPassword('wrong'))->toBeFalse();

    expect(OrganizationMembership::where('user_id', $operator->id)->where('role', 'org:workspace_owner')->exists())->toBeTrue();

    expect(SigningKey::where('environment_id', $result['environment']->id)->where('status', 'active')->count())->toBe(1);

    $secretRows = ApiKey::where('environment_id', $result['environment']->id)->get();
    expect($secretRows)->toHaveCount(2);
    expect($secretRows->pluck('kind')->sort()->values()->all())->toBe(['publishable', 'secret']);

    expect($result['secret_key'])->toStartWith('sk_live_');
    expect($result['publishable_key'])->toStartWith('pk_live_');
});

it('is idempotent — re-running returns null and does not duplicate state', function (): void {
    bootstrapWith();

    $service = app(BootstrapService::class);
    $second = $service->run([
        'admin_email' => 'op@example.com',
        'admin_password' => 'super-secret-password',
        'workspace_name' => 'Acme Inc',
        'app_url' => 'https://authn.local',
    ]);

    expect($second)->toBeNull();
    expect(Project::where('is_system', true)->count())->toBe(1);
    expect(User::count())->toBe(1);
    expect(ApiKey::count())->toBe(2);
    expect(SigningKey::count())->toBe(1);
});

it('refuses to bootstrap without an admin email or password', function (): void {
    $service = app(BootstrapService::class);

    expect(fn () => $service->run(['admin_email' => '', 'admin_password' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'Bootstrap requires admin_email and admin_password');

    expect(fn () => $service->run(['admin_email' => 'op@example.com', 'admin_password' => '']))
        ->toThrow(InvalidArgumentException::class, 'Bootstrap requires admin_email and admin_password');
});

it('rejects malformed admin email addresses', function (): void {
    $service = app(BootstrapService::class);

    expect(fn () => $service->run(['admin_email' => 'not-an-email', 'admin_password' => 'super-secret']))
        ->toThrow(InvalidArgumentException::class, 'is not a valid email address');
});

it('hashes the secret API key with SHA-256 before storing it', function (): void {
    $result = bootstrapWith();

    $secretRow = ApiKey::where('environment_id', $result['environment']->id)
        ->where('kind', 'secret')
        ->firstOrFail();

    expect($secretRow->hashed_secret)->toBe(hash('sha256', $result['secret_key']));
    // The plaintext should never appear in the row.
    expect($secretRow->getAttributes())->not->toHaveKey('plaintext_secret');
});

it('enforces uniqueness on (owner_organization_id, slug)', function (): void {
    bootstrapWith();
    $workspace = Organization::firstOrFail();

    Project::create([
        'owner_organization_id' => $workspace->id,
        'name' => 'First',
        'slug' => 'duplicate',
    ]);

    expect(fn () => Project::create([
        'owner_organization_id' => $workspace->id,
        'name' => 'Second',
        'slug' => 'duplicate',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('exposes Project->is_admin_project for the seeded _admin row', function (): void {
    bootstrapWith();
    $admin = Project::where('slug', Project::SYSTEM_SLUG)->firstOrFail();

    expect($admin->is_admin_project)->toBeTrue();
});
