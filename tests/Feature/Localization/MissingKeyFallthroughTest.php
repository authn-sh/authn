<?php

declare(strict_types=1);

use App\Localization\Localizer;
use App\Models\Environment;
use App\Models\Project;

function makeEnvForMissingKey(string $slug = 'mke'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('returns the dot-keyed name when the key is not in any catalog', function (): void {
    $env = makeEnvForMissingKey('mk1');

    $rendered = (new Localizer)->localize($env, 'en-US', 'this.key.does.not.exist');

    expect($rendered)->toBe('this.key.does.not.exist');
});

it('falls through canonical-locale → fallback-locale → key', function (): void {
    $env = makeEnvForMissingKey('mk2');
    // ja-JP isn't a shipped locale; the fallback is en-US, which has the key.
    $rendered = (new Localizer)->localize($env, 'ja-JP', 'signIn.start.actionLink');

    expect($rendered)->toBe('Sign up');
});

it('falls through operator overrides for the active locale first', function (): void {
    $env = makeEnvForMissingKey('mk3');
    $env->forceFill([
        'localization' => [
            'default_locale' => 'en-US',
            'fallback_locale' => 'en-US',
            'supported_locales' => ['en-US', 'pt-BR'],
            'overrides' => [
                'pt-BR' => ['formButtonPrimary' => 'Vamos lá'],
            ],
        ],
    ])->save();

    $rendered = (new Localizer)->localize($env->refresh(), 'pt-BR', 'formButtonPrimary');

    expect($rendered)->toBe('Vamos lá');
});
