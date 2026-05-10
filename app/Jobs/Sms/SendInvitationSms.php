<?php

declare(strict_types=1);

namespace App\Jobs\Sms;

use App\Models\Environment;
use App\Models\SmsTemplate;
use App\Sms\SmsPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sibling of `SendInvitationEmail`. Renders the `invitation` SmsTemplate
 * + dispatches via the env's driver.
 */
final class SendInvitationSms implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $environmentId,
        public readonly string $toNumber,
        public readonly string $organizationName,
        public readonly string $actionUrl,
    ) {
        $this->onQueue('sms');
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SmsPipeline $pipeline): void
    {
        $env = Environment::query()->withoutGlobalScopes()->where('id', $this->environmentId)->first();
        if ($env === null) {
            Log::warning('sms_environment_missing', ['environment_id' => $this->environmentId]);

            return;
        }

        $pipeline->dispatch(
            environment: $env,
            templateSlug: SmsTemplate::SLUG_INVITATION,
            toNumber: $this->toNumber,
            vars: [
                'organization' => ['name' => $this->organizationName],
                'action_url' => $this->actionUrl,
            ],
        );
    }
}
