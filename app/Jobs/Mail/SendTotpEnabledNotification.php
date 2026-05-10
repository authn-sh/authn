<?php

declare(strict_types=1);

namespace App\Jobs\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Heads-up email sent when a user enables TOTP. Body wiring (template
 * lookup, EmailPipeline dispatch) lands in AU-9; this stub keeps the
 * dispatch site stable so AU-9 is a one-line replace.
 */
final class SendTotpEnabledNotification implements ShouldQueue
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

    public function handle(): void
    {
        $user = User::query()->withoutGlobalScopes()->where('id', $this->userId)->first();
        if ($user === null) {
            Log::warning('mail_user_missing', ['user_id' => $this->userId, 'template' => 'totp_enabled']);

            return;
        }
        Log::info('mfa.totp_enabled_notification.queued', [
            'user_id' => $user->id,
            'environment_id' => $user->environment_id,
        ]);
    }
}
