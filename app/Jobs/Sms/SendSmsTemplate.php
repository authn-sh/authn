<?php

declare(strict_types=1);

namespace App\Jobs\Sms;

use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\Verification;
use App\Sms\SmsPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generic SMS dispatcher — renders an arbitrary template with a caller-
 * supplied context bag and ships through the env's resolved driver.
 *
 * Carries scalar IDs only so the queue payload survives model deletion;
 * the handler does the lookups itself.
 */
final class SendSmsTemplate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $vars
     */
    public function __construct(
        public readonly string $environmentId,
        public readonly string $templateSlug,
        public readonly string $toNumber,
        public readonly array $vars,
        public readonly ?string $phoneNumberId = null,
        public readonly ?string $verificationId = null,
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

        $phone = $this->phoneNumberId !== null
            ? PhoneNumber::query()->withoutGlobalScopes()->where('id', $this->phoneNumberId)->first()
            : null;
        $verification = $this->verificationId !== null
            ? Verification::query()->withoutGlobalScopes()->where('id', $this->verificationId)->first()
            : null;

        $pipeline->dispatch(
            environment: $env,
            templateSlug: $this->templateSlug,
            toNumber: $this->toNumber,
            vars: $this->vars,
            phoneNumber: $phone,
            verification: $verification,
        );
    }
}
