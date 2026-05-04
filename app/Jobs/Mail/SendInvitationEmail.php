<?php

declare(strict_types=1);

namespace App\Jobs\Mail;

use App\Mail\EmailPipeline;
use App\Models\EmailTemplate;
use App\Models\Environment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Application-level invitation email. Built on top of EmailPipeline so it
 * honours the same test-mode / delivered_by_us / driver branching as the
 * verification flow.
 */
final class SendInvitationEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $publicMetadata
     */
    public function __construct(
        public readonly string $environmentId,
        public readonly string $emailAddress,
        public readonly string $url,
        public readonly array $publicMetadata = [],
        public readonly string $templateSlug = EmailTemplate::SLUG_INVITATION,
    ) {
        $this->onQueue('mail');
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(EmailPipeline $pipeline): void
    {
        $env = Environment::query()->withoutGlobalScopes()->where('id', $this->environmentId)->first();
        if ($env === null) {
            Log::warning('mail_environment_missing', ['environment_id' => $this->environmentId]);

            return;
        }

        $pipeline->dispatch(
            environment: $env,
            templateSlug: $this->templateSlug,
            toEmail: $this->emailAddress,
            toName: null,
            vars: [
                'action_url' => $this->url,
                'metadata' => $this->publicMetadata,
            ],
        );
    }
}
