<?php

declare(strict_types=1);

namespace App\Observers;

use App\Auth\Oauth\OauthProviderSeeder;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\SmsTemplate;
use App\Services\Tenancy\RoleSeeder;

/**
 * Seeds the per-env defaults — system permissions + default roles, plus
 * the active EmailTemplate / SmsTemplate sets + OauthProvider preset rows
 * — into every freshly-minted environment.
 *
 * The bootstrap service calls RoleSeeder directly so it keeps a handle on
 * the seeded admin role for the workspace owner membership; everywhere
 * else (Dashboard creating a new env, factories, …) the observer is the
 * sanctioned path.
 */
final class EnvironmentObserver
{
    public function __construct(
        private readonly RoleSeeder $seeder,
        private readonly OauthProviderSeeder $oauthSeeder,
    ) {}

    public function created(Environment $environment): void
    {
        $this->seeder->seed($environment);
        $this->seedDefaultEmailTemplates($environment);
        $this->seedDefaultSmsTemplates($environment);
        $this->oauthSeeder->seed($environment);
    }

    private function seedDefaultEmailTemplates(Environment $environment): void
    {
        foreach (EmailTemplate::DEFAULT_TEMPLATES as $slug => $payload) {
            $exists = EmailTemplate::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $environment->id)
                ->where('slug', $slug)
                ->exists();
            if ($exists) {
                continue;
            }
            EmailTemplate::query()->withoutGlobalScopes()->create([
                'environment_id' => $environment->id,
                'slug' => $slug,
                'subject' => $payload['subject'],
                'body_markup' => $payload['body_markup'],
                'body_html' => $payload['body_html'],
            ]);
        }
    }

    private function seedDefaultSmsTemplates(Environment $environment): void
    {
        foreach (SmsTemplate::DEFAULT_TEMPLATES as $slug => $payload) {
            $exists = SmsTemplate::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $environment->id)
                ->where('slug', $slug)
                ->exists();
            if ($exists) {
                continue;
            }
            SmsTemplate::query()->withoutGlobalScopes()->create([
                'environment_id' => $environment->id,
                'slug' => $slug,
                'body' => $payload['body'],
            ]);
        }
    }
}
