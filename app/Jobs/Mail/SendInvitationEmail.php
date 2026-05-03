<?php

declare(strict_types=1);

namespace App\Jobs\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue job that renders the configured `invitation` template and ships it
 * via the env's mail driver. v0.1 placeholder — AU-14 lights up the actual
 * MJML renderer + driver abstraction. For now we just log so the BAPI
 * invitation flow can be tested end-to-end.
 */
final class SendInvitationEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $publicMetadata
     */
    public function __construct(
        public readonly string $environmentId,
        public readonly string $emailAddress,
        public readonly string $url,
        public readonly array $publicMetadata = [],
        public readonly string $templateSlug = 'invitation',
    ) {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        Log::info('SendInvitationEmail (AU-14 stub)', [
            'environment_id' => $this->environmentId,
            'to' => $this->emailAddress,
            'template' => $this->templateSlug,
            'has_metadata' => $this->publicMetadata !== [],
        ]);
    }
}
