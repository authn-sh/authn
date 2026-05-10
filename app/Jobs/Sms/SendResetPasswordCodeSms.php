<?php

declare(strict_types=1);

namespace App\Jobs\Sms;

use App\Mail\Renderer;
use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\SmsTemplate;
use App\Models\Verification;
use App\Sms\SmsPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sibling of `SendResetPasswordCodeEmail`. Renders the
 * `reset_password_code` SmsTemplate + dispatches via the env's driver.
 */
final class SendResetPasswordCodeSms implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $environmentId,
        public readonly string $toNumber,
        public readonly string $code,
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

        $expiryMinutes = $verification !== null
            ? max(1, (int) round(($verification->expire_at->getTimestamp() - now()->getTimestamp()) / 60))
            : 10;

        $pipeline->dispatch(
            environment: $env,
            templateSlug: SmsTemplate::SLUG_RESET_PASSWORD_CODE,
            toNumber: $this->toNumber,
            vars: [
                'otp_code' => $this->code,
                'expiry_minutes' => $expiryMinutes,
                'expires_at_human' => $verification !== null
                    ? Renderer::humanExpires($verification->expire_at)
                    : 'in 10 minutes',
            ],
            phoneNumber: $phone,
            verification: $verification,
        );
    }
}
