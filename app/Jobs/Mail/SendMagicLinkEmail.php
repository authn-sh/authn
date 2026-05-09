<?php

declare(strict_types=1);

namespace App\Jobs\Mail;

use App\Mail\EmailPipeline;
use App\Mail\Renderer;
use App\Models\EmailAddress;
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
 * Sends the magic-link email for the email_link sign-in / sign-up flow
 * (AU-10). Distinct from SendVerificationEmail so the template slug
 * + payload can carry the action_url instead of an OTP code.
 */
final class SendMagicLinkEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $environmentId,
        public readonly string $emailAddress,
        public readonly string $actionUrl,
        public readonly string $templateSlug,
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
            templateSlug: $this->templateSlug,
            toEmail: $this->emailAddress,
            toName: $user?->first_name,
            vars: [
                'user' => [
                    'first_name' => (string) ($user?->first_name ?? ''),
                    'last_name' => (string) ($user?->last_name ?? ''),
                    'email_address' => $this->emailAddress,
                ],
                'action_url' => $this->actionUrl,
                'expires_at_human' => $verification !== null
                    ? Renderer::humanExpires($verification->expire_at)
                    : 'in 5 minutes',
            ],
            emailAddress: $emailRow,
            verification: $verification,
        );
    }
}
