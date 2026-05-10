<?php

declare(strict_types=1);

use App\Mail\Renderer;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SmsTemplate;
use App\Observers\EnvironmentObserver;

function envForSmsTpl(string $slug = 'env'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);

    return Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
    ]);
}

it('seeds the three default SMS templates on environment creation', function (): void {
    $env = envForSmsTpl();

    $rows = SmsTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->orderBy('slug')
        ->get();

    expect($rows->pluck('slug')->all())->toBe([
        SmsTemplate::SLUG_INVITATION,
        SmsTemplate::SLUG_RESET_PASSWORD_CODE,
        SmsTemplate::SLUG_VERIFICATION_CODE,
    ]);

    foreach ($rows as $row) {
        expect($row->id)->toStartWith('tmpl_');
        expect($row->body)->not->toBe('');
        expect($row->delivered_by_us)->toBeTrue();
    }
});

it('exposes the canonical default body for each slug', function (): void {
    $env = envForSmsTpl();

    $verification = SmsTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('slug', SmsTemplate::SLUG_VERIFICATION_CODE)
        ->firstOrFail();

    expect($verification->body)->toContain('{{otp_code}}');
    expect($verification->body)->toContain('{{app.name}}');
});

it('seeding is idempotent — calling created() twice does not duplicate rows', function (): void {
    $env = envForSmsTpl();

    app(EnvironmentObserver::class)->created($env);

    $count = SmsTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->count();

    expect($count)->toBe(3);
});

it('renders a template against the env defaults via the shared Renderer', function (): void {
    config()->set('app.name', 'Authn');
    $env = envForSmsTpl();
    $env->forceFill(['appearance' => ['application_name' => 'Acme']])->save();

    $template = SmsTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('slug', SmsTemplate::SLUG_VERIFICATION_CODE)
        ->firstOrFail();

    $rendered = app(Renderer::class)->renderSms($template, $env->refresh(), [
        'otp_code' => '123456',
        'expiry_minutes' => 10,
    ]);

    expect($rendered)->toBe('Acme: your verification code is 123456. Expires in 10 minutes.');
});
