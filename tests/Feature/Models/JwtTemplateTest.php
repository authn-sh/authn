<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\JwtTemplate;
use App\Models\Project;
use Illuminate\Database\QueryException;

function makeEnvForJwtTemplate(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('mints a jtmpl_ prefixed id and casts claims to an array', function (): void {
    $env = makeEnvForJwtTemplate();

    $tmpl = JwtTemplate::factory()->create([
        'environment_id' => $env->id,
        'name' => 'api-access',
        'claims' => ['sub' => '{{user.id}}', 'org' => '{{org.slug}}'],
    ]);

    expect($tmpl->id)->toStartWith('jtmpl_');
    expect($tmpl->claims)->toBe(['sub' => '{{user.id}}', 'org' => '{{org.slug}}']);
    expect($tmpl->lifetime)->toBe(60);
    expect($tmpl->allowed_clock_skew)->toBe(5);
    expect($tmpl->signing_algorithm)->toBe(JwtTemplate::ALG_RS256);
});

it('encrypts custom_signing_key and hides it from arrays', function (): void {
    $env = makeEnvForJwtTemplate();

    $pem = "-----BEGIN PRIVATE KEY-----\nMIIBfake\n-----END PRIVATE KEY-----";
    $tmpl = JwtTemplate::factory()->withCustomSigningKey($pem)->create([
        'environment_id' => $env->id,
    ]);

    expect($tmpl->custom_signing_key)->toBe($pem);

    $raw = (string) DB::table('jwt_templates')->where('id', $tmpl->id)->value('custom_signing_key');
    expect($raw)->not->toBe($pem);
    expect(strlen($raw))->toBeGreaterThan(20);

    expect(array_key_exists('custom_signing_key', $tmpl->toArray()))->toBeFalse();
});

it('enforces unique (environment_id, name)', function (): void {
    $env = makeEnvForJwtTemplate();

    JwtTemplate::factory()->create(['environment_id' => $env->id, 'name' => 'api']);

    expect(fn () => JwtTemplate::factory()->create(['environment_id' => $env->id, 'name' => 'api']))
        ->toThrow(QueryException::class);
});

it('allows the same name across distinct environments', function (): void {
    $env1 = makeEnvForJwtTemplate('one');
    $env2 = makeEnvForJwtTemplate('two');

    $t1 = JwtTemplate::factory()->create(['environment_id' => $env1->id, 'name' => 'api']);
    $t2 = JwtTemplate::factory()->create(['environment_id' => $env2->id, 'name' => 'api']);

    expect($t1->id)->not->toBe($t2->id);
});

it('soft-deletes via removed_at', function (): void {
    $env = makeEnvForJwtTemplate();
    app()->instance(Environment::class, $env);

    $tmpl = JwtTemplate::factory()->create(['environment_id' => $env->id]);
    $tmpl->delete();

    expect(JwtTemplate::query()->find($tmpl->id))->toBeNull();
    expect(JwtTemplate::withTrashed()->find($tmpl->id)?->removed_at)->not->toBeNull();
    app()->forgetInstance(Environment::class);
});

it('honours EnvironmentScope', function (): void {
    $env1 = makeEnvForJwtTemplate('one');
    $env2 = makeEnvForJwtTemplate('two');

    JwtTemplate::factory()->create(['environment_id' => $env1->id, 'name' => 'env1-tmpl']);
    JwtTemplate::factory()->create(['environment_id' => $env2->id, 'name' => 'env2-tmpl']);

    app()->instance(Environment::class, $env1);
    expect(JwtTemplate::query()->pluck('name')->all())->toBe(['env1-tmpl']);
    app()->forgetInstance(Environment::class);
});

it('belongs to an Environment', function (): void {
    $env = makeEnvForJwtTemplate();
    $tmpl = JwtTemplate::factory()->create(['environment_id' => $env->id]);

    expect($tmpl->environment->id)->toBe($env->id);
});
