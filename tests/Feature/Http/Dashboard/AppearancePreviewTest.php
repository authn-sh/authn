<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

it('mints a 5-minute preview token and returns the signed preview URL', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->postJson('http://dashboard.authn.local/acme/production/configure/appearance/preview', [
            'variables' => ['colorPrimary' => '#ff00aa'],
            'elements' => ['signIn.root' => 'rounded-xl'],
            'layout' => ['showOptionalFields' => true],
        ]);
    $r->assertOk()->assertJsonStructure(['preview_url', 'expires_at_ms']);

    $previewUrl = (string) $r->json('preview_url');
    expect($previewUrl)->toContain('/_preview/appearance/');

    $token = substr($previewUrl, (int) strrpos($previewUrl, '/') + 1);
    $row = DB::table('appearance_preview_drafts')->where('token', $token)->first();
    expect($row)->not->toBeNull();
    expect($row->environment_id)->toBe($env->id);
    $draft = json_decode((string) $row->draft, true);
    expect($draft['variables']['colorPrimary'])->toBe('#ff00aa');
    expect($draft['elements']['signIn.root'])->toBe('rounded-xl');
    expect($draft['layout']['showOptionalFields'])->toBeTrue();
});

it('refuses preview minting for non-existent envs', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);

    $r = $this->withHeaders(dashHeaders($bs['jwt']))
        ->postJson('http://dashboard.authn.local/nope/none/configure/appearance/preview', [
            'variables' => ['x' => 'y'],
        ]);
    $r->assertStatus(404)->assertJsonPath('errors.0.code', 'environment_not_found');
});

it('renders the Account Portal SignIn with the draft appearance applied when the token resolves', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
        'appearance' => ['variables' => ['colorPrimary' => '#000000']],
    ]);

    // Mint the token via the dashboard endpoint so the cache write happens
    // inside the same kernel pass that the subsequent HTTP test request
    // observes — the array cache store doesn't survive between test-host
    // `Cache::put()` and the next `$this->get()` call's kernel boot.
    $mint = $this->withHeaders(dashHeaders($bs['jwt']))
        ->postJson('http://dashboard.authn.local/acme/production/configure/appearance/preview', [
            'variables' => ['colorPrimary' => '#ff00aa'],
            'elements' => ['signIn.root' => 'rounded-xl'],
        ]);
    $mint->assertOk();
    $previewUrl = (string) $mint->json('preview_url');
    $token = substr($previewUrl, (int) strrpos($previewUrl, '/') + 1);

    $r = $this->withHeaders([
        'Host' => 'acme.authn.local',
        'Accept' => 'application/json',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
    ])->get('http://acme.authn.local/_preview/appearance/'.$token);
    $r->assertOk()->assertJsonPath('component', 'AccountPortal/SignIn');
    // Draft override applied, not the env's stored appearance. `signIn.root`
    // contains a dot so we have to walk the props manually rather than use
    // Laravel's dot-path assertion.
    $appearance = (array) $r->json('props.environment.appearance');
    expect($appearance['variables']['colorPrimary'] ?? null)->toBe('#ff00aa');
    expect($appearance['elements']['signIn.root'] ?? null)->toBe('rounded-xl');
});

it('rejects an expired or unknown preview token with 404', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);

    $r = $this->get('http://acme.authn.local/_preview/appearance/'.str_repeat('z', 48), [
        'Host' => 'acme.authn.local',
    ]);
    $r->assertStatus(404);
});

it('rejects a preview token issued for a different env (cross-env replay)', function (): void {
    $f = bootAdminEnv();
    $bs = operatorWithMembership($f['env']);
    $project = Project::create(['name' => 'Acme', 'slug' => 'acme', 'owner_organization_id' => $bs['workspace']->id]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => 'production',
        'routing_label' => 'acme',
        'allowed_origins' => [],
    ]);
    Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_DEVELOPMENT,
        'slug' => 'development',
        'routing_label' => 'acme-dev',
        'allowed_origins' => [],
    ]);

    // Mint a token bound to envA via the dashboard endpoint, then replay it
    // against envB's host.
    $mint = $this->withHeaders(dashHeaders($bs['jwt']))
        ->postJson('http://dashboard.authn.local/acme/production/configure/appearance/preview', [
            'variables' => ['colorPrimary' => '#cc0000'],
        ]);
    $mint->assertOk();
    $previewUrl = (string) $mint->json('preview_url');
    $token = substr($previewUrl, (int) strrpos($previewUrl, '/') + 1);

    $r = $this->get('http://acme-dev.authn.local/_preview/appearance/'.$token, [
        'Host' => 'acme-dev.authn.local',
    ]);
    $r->assertStatus(404);
});
