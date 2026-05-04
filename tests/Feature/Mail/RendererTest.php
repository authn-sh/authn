<?php

declare(strict_types=1);

use App\Mail\Renderer;
use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mail\MailTestSupport;

uses(RefreshDatabase::class);

it('substitutes every standard variable', function (): void {
    $env = MailTestSupport::bootEnv();
    $template = EmailTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('slug', EmailTemplate::SLUG_VERIFICATION_CODE)
        ->first();

    $rendered = app(Renderer::class)->render($template, $env, [
        'user' => ['first_name' => 'Alice', 'last_name' => 'Smith', 'email_address' => 'a@example.com'],
        'code' => '424242',
        'expires_at_human' => 'in 10 minutes',
    ]);

    expect($rendered->subject)->toBe('Your Acme verification code');
    expect($rendered->html)->toContain('Hi Alice')
        ->toContain('424242')
        ->toContain('in 10 minutes');
    expect($rendered->text)->toContain('Hi Alice')->toContain('424242');
});

it('html-escapes user-supplied substitutions to prevent XSS', function (): void {
    $env = MailTestSupport::bootEnv();
    $template = EmailTemplate::query()->withoutGlobalScopes()
        ->where('environment_id', $env->id)
        ->where('slug', EmailTemplate::SLUG_VERIFICATION_CODE)
        ->first();

    $rendered = app(Renderer::class)->render($template, $env, [
        'user' => ['first_name' => '<script>alert(1)</script>', 'last_name' => '', 'email_address' => 'x'],
        'code' => '424242',
        'expires_at_human' => 'in 10 minutes',
    ]);

    expect($rendered->html)->not->toContain('<script>');
    expect($rendered->html)->toContain('&lt;script&gt;');
});

it('humanExpires formats the right unit', function (): void {
    expect(Renderer::humanExpires(now()->addMinutes(10)))->toBe('in 10 minutes');
    expect(Renderer::humanExpires(now()->addHours(2)))->toBe('in 2 hours');
    expect(Renderer::humanExpires(now()->addDays(3)))->toBe('in 3 days');
});
