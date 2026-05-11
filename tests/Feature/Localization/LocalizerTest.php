<?php

declare(strict_types=1);

use App\Localization\Localizer;
use App\Models\Environment;
use App\Models\Project;

function makeEnvForLocalizer(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('renders canonical strings with placeholder substitution', function (): void {
    $env = makeEnvForLocalizer('loc1');
    $localizer = new Localizer;

    $rendered = $localizer->localize($env, 'en-US', 'signIn.start.title', ['applicationName' => 'Acme']);

    expect($rendered)->toBe('Sign in to Acme');
});

it('falls back to fallback_locale when the active locale lacks an override and is not bundled', function (): void {
    $env = makeEnvForLocalizer('loc2');
    $localizer = new Localizer;

    $rendered = $localizer->localize($env, 'ja-JP', 'signIn.start.actionLink');

    expect($rendered)->toBe('Sign up');
});

it('honours operator overrides ahead of canonical defaults', function (): void {
    $env = makeEnvForLocalizer('loc3');
    $env->forceFill([
        'localization' => [
            'default_locale' => 'en-US',
            'fallback_locale' => 'en-US',
            'supported_locales' => ['en-US'],
            'overrides' => [
                'en-US' => ['signIn.start.title' => 'Welcome to Acme'],
            ],
        ],
    ])->save();
    $localizer = new Localizer;

    $rendered = $localizer->localize($env->refresh(), 'en-US', 'signIn.start.title', ['applicationName' => 'Ignored']);

    expect($rendered)->toBe('Welcome to Acme');
});

it('merges canonical fallback + locale + overrides into the public catalog', function (): void {
    $env = makeEnvForLocalizer('loc4');
    $env->forceFill([
        'localization' => [
            'default_locale' => 'pt-BR',
            'fallback_locale' => 'en-US',
            'supported_locales' => ['en-US', 'pt-BR'],
            'overrides' => [
                'pt-BR' => ['signIn.start.title' => 'Bem-vindo à Acme'],
            ],
        ],
    ])->save();
    $localizer = new Localizer;

    $catalog = $localizer->catalog($env->refresh(), 'pt-BR');

    expect($catalog['signIn.start.title'])->toBe('Bem-vindo à Acme');
    expect($catalog)->toHaveKey('formButtonPrimary');
});
