<?php

declare(strict_types=1);

namespace App\Jobs\Mail;

use App\Mail\EmailPipeline;
use App\Mail\Renderer;
use App\Models\EmailAddress;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reset-password OTP email. Always rendered against the
 * `reset_password_code` template; runs through EmailPipeline.
 */
final class SendResetPasswordCodeEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $environmentId,
        public readonly string $emailAddress,
        public readonly string $code,
        public readonly ?string $verificationId = null,
        public readonly ?string $emailAddressId = null,
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

        $emailRow = $this->emailAddressId !== null
            ? EmailAddress::query()->withoutGlobalScopes()->where('id', $this->emailAddressId)->first()
            : EmailAddress::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->where('email_address', strtolower($this->emailAddress))
                ->first();
        $verification = $this->verificationId !== null
            ? Verification::query()->withoutGlobalScopes()->where('id', $this->verificationId)->first()
            : null;
        $user = $emailRow?->user_id !== null
            ? User::query()->withoutGlobalScopes()->where('id', $emailRow->user_id)->first()
            : null;

        $pipeline->dispatch(
            environment: $env,
            templateSlug: EmailTemplate::SLUG_RESET_PASSWORD_CODE,
            toEmail: $this->emailAddress,
            toName: $user?->first_name,
            vars: [
                'user' => [
                    'first_name' => (string) ($user?->first_name ?? ''),
                    'last_name' => (string) ($user?->last_name ?? ''),
                    'email_address' => $this->emailAddress,
                ],
                'code' => $this->code,
                'expires_at_human' => $verification !== null
                    ? Renderer::humanExpires($verification->expire_at)
                    : 'in 10 minutes',
            ],
            emailAddress: $emailRow,
            verification: $verification,
        );
    }
}
