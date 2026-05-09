<?php

declare(strict_types=1);

use App\Models\EmailTemplate;
use App\Services\Tenancy\BootstrapService;


it('authn:bootstrap seeds the v0.1 active template set on a fresh env', function (): void {
    $service = app(BootstrapService::class);
    $result = $service->run([
        'admin_email' => 'op@example.com',
        'admin_password' => 'super-secret-password',
        'workspace_name' => 'Acme',
        'app_url' => 'https://authn.local',
    ]);

    expect($result)->not->toBeNull();
    $envId = $result['environment']->id;

    foreach (array_keys(EmailTemplate::DEFAULT_TEMPLATES) as $slug) {
        expect(
            EmailTemplate::query()->withoutGlobalScopes()
                ->where('environment_id', $envId)
                ->where('slug', $slug)
                ->exists()
        )->toBeTrue("Template {$slug} should be seeded");
    }
});
