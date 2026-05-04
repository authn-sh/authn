<?php

declare(strict_types=1);

namespace App\Jobs\Mail;

use App\Mail\EmailPipeline;
use App\Models\EmailAddress;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fires after a successful `change_password` so the user has an audit trail
 * of the change. No code, no link — pure notification.
 */
final class SendPasswordChangedNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $userId)
    {
        $this->onQueue('mail');
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(EmailPipeline $pipeline): void
    {
        $user = User::query()->withoutGlobalScopes()->where('id', $this->userId)->first();
        if ($user === null) {
            Log::warning('mail_user_missing', ['user_id' => $this->userId]);

            return;
        }
        $env = Environment::query()->withoutGlobalScopes()->where('id', $user->environment_id)->first();
        if ($env === null) {
            return;
        }
        $email = EmailAddress::query()->withoutGlobalScopes()
            ->where('id', (string) $user->primary_email_address_id)
            ->first();
        if ($email === null) {
            return;
        }

        $pipeline->dispatch(
            environment: $env,
            templateSlug: EmailTemplate::SLUG_PASSWORD_CHANGED,
            toEmail: $email->email_address,
            toName: $user->first_name,
            vars: [
                'user' => [
                    'first_name' => (string) ($user->first_name ?? ''),
                    'last_name' => (string) ($user->last_name ?? ''),
                    'email_address' => $email->email_address,
                ],
            ],
        );
    }
}
