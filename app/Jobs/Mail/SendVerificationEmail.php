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
 * Queue job that renders the `verification_code` email template and
 * dispatches it via the env's mail driver.
 *
 * v0.1 placeholder — AU-14 lights up MJML rendering + driver
 * abstraction. For now the job just logs the payload so the sign-in /
 * sign-up flows can be tested end-to-end.
 */
final class SendVerificationEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $environmentId,
        public readonly string $emailAddress,
        public readonly string $code,
        public readonly string $purpose,
    ) {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        Log::info('SendVerificationEmail (AU-14 stub)', [
            'environment_id' => $this->environmentId,
            'to' => $this->emailAddress,
            'purpose' => $this->purpose,
            'code_length' => strlen($this->code),
        ]);
    }
}
