<?php

declare(strict_types=1);

namespace App\Jobs\Mail;

use App\Mail\EmailPipeline;
use App\Mail\Renderer;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Renders + dispatches the organization-invitation email (AU-14).
 *
 * Lookup happens from scalar IDs so the job payload survives row
 * deletion — the handler logs and exits if the invitation has been
 * revoked between dispatch and handle.
 */
final class SendOrganizationInvitationEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $invitationId,
        public readonly string $url,
    ) {
        $this->onQueue('mail');
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(EmailPipeline $pipeline): void
    {
        $invitation = OrganizationInvitation::query()
            ->withoutGlobalScopes()
            ->where('id', $this->invitationId)
            ->first();
        if ($invitation === null) {
            Log::info('org_invitation_email_invitation_missing', ['invitation_id' => $this->invitationId]);

            return;
        }

        $env = Environment::query()->withoutGlobalScopes()->where('id', $invitation->environment_id)->first();
        if ($env === null) {
            Log::warning('mail_environment_missing', ['environment_id' => $invitation->environment_id]);

            return;
        }

        $org = Organization::query()->withoutGlobalScopes()->where('id', $invitation->organization_id)->first();
        $role = $invitation->role_id !== null
            ? Role::query()->withoutGlobalScopes()->where('id', $invitation->role_id)->first()
            : null;
        $inviter = $invitation->inviter_user_id !== null
            ? User::query()->withoutGlobalScopes()->where('id', $invitation->inviter_user_id)->first()
            : null;

        $inviterName = $inviter !== null
            ? trim((string) ($inviter->first_name.' '.$inviter->last_name))
            : '';
        if ($inviterName === '' && $inviter !== null) {
            $inviterName = (string) ($inviter->username ?? 'A teammate');
        }

        $pipeline->dispatch(
            environment: $env,
            templateSlug: EmailTemplate::SLUG_ORGANIZATION_INVITATION,
            toEmail: $invitation->email_address,
            toName: null,
            vars: [
                'inviter' => [
                    'name' => $inviterName !== '' ? $inviterName : 'A teammate',
                    'email' => $inviter?->primaryEmailAddress?->email_address ?? '',
                ],
                'organization' => [
                    'id' => $org?->id ?? '',
                    'name' => $org?->name ?? '',
                    'slug' => $org?->slug ?? '',
                ],
                'role' => [
                    'key' => $role?->key ?? '',
                    'name' => $role?->name ?? '',
                ],
                'action_url' => $this->url,
                'expires_at_human' => $invitation->expires_at !== null
                    ? Renderer::humanExpires($invitation->expires_at)
                    : 'in 30 days',
            ],
            emailAddress: null,
            verification: null,
        );
    }
}
