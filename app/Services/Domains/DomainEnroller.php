<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Events\Organizations\OrganizationInvitationCreated;
use App\Events\Organizations\OrganizationMembershipRequestCreated;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\OrganizationDomain;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\OrganizationMembershipRequest;
use App\Models\Role;
use App\Services\Tickets\TicketIssuer;
use App\Support\Url;

/**
 * Runs the domain-match step of sign-up promotion (AU-9). Given a verified
 * EmailAddress, finds every verified `OrganizationDomain` matching the
 * email's host part and creates the appropriate `OrganizationInvitation`
 * (`automatic_invitation`) or `OrganizationMembershipRequest`
 * (`automatic_suggestion`) per domain.
 *
 * `manual_invitation` is a no-op here — the org admin issues invitations
 * through the BAPI / FAPI surface explicitly.
 */
final class DomainEnroller
{
    public function __construct(private readonly TicketIssuer $tickets) {}

    public function enroll(Environment $environment, EmailAddress $email): void
    {
        $host = $this->extractHost((string) $email->email_address);
        if ($host === null) {
            return;
        }

        $domains = OrganizationDomain::query()
            ->where('environment_id', $environment->id)
            ->where('verified', true)
            ->where('name', $host)
            ->whereIn('enrollment_mode', [
                OrganizationDomain::MODE_AUTOMATIC_INVITATION,
                OrganizationDomain::MODE_AUTOMATIC_SUGGESTION,
            ])
            ->with('organization')
            ->get();

        foreach ($domains as $domain) {
            if ($domain->enrollment_mode === OrganizationDomain::MODE_AUTOMATIC_INVITATION) {
                $this->autoInvite($environment, $email, $domain);
            } else {
                $this->autoSuggest($environment, $email, $domain);
            }
        }
    }

    private function autoInvite(Environment $env, EmailAddress $email, OrganizationDomain $domain): void
    {
        $org = $domain->organization;
        if ($org === null) {
            return;
        }
        if ($org->max_allowed_memberships !== null && $org->members_count >= $org->max_allowed_memberships) {
            return;
        }

        $duplicate = OrganizationInvitation::query()
            ->where('organization_id', $org->id)
            ->where('email_address', strtolower((string) $email->email_address))
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->exists();
        if ($duplicate) {
            return;
        }

        $defaultRole = Role::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('is_default', true)
            ->first();
        if ($defaultRole === null) {
            return;
        }

        $expiresAt = now()->addSeconds(30 * 24 * 60 * 60);
        $invitation = OrganizationInvitation::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'email_address' => strtolower((string) $email->email_address),
            'role_id' => $defaultRole->id,
            'inviter_user_id' => null,
            'redirect_url' => null,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'public_metadata' => ['source' => 'domain_enrollment'],
            'expires_at' => $expiresAt,
        ]);
        $org->increment('pending_invitations_count');
        $domain->increment('total_pending_invitations');

        $jwt = $this->tickets->issue($env, max(60, $expiresAt->getTimestamp() - now()->getTimestamp()), [
            'sub' => $invitation->email_address,
            'sid' => $invitation->id,
            'purpose' => 'organization_invitation',
            'metadata' => is_array($invitation->public_metadata) ? $invitation->public_metadata : [],
            'redirect_url' => null,
        ]);
        $url = $this->ticketUrl($env, $jwt);

        OrganizationInvitationCreated::dispatch($invitation, $url);
    }

    private function autoSuggest(Environment $env, EmailAddress $email, OrganizationDomain $domain): void
    {
        $org = $domain->organization;
        if ($org === null || $email->user_id === null) {
            return;
        }

        $alreadyMember = OrganizationMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $email->user_id)
            ->exists();
        if ($alreadyMember) {
            return;
        }

        $duplicate = OrganizationMembershipRequest::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $email->user_id)
            ->where('status', OrganizationMembershipRequest::STATUS_PENDING)
            ->exists();
        if ($duplicate) {
            return;
        }

        $req = OrganizationMembershipRequest::create([
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'user_id' => $email->user_id,
            'status' => OrganizationMembershipRequest::STATUS_PENDING,
        ]);
        $domain->increment('total_pending_suggestions');

        OrganizationMembershipRequestCreated::dispatch($req);
    }

    private function extractHost(string $emailAddress): ?string
    {
        $at = strrpos($emailAddress, '@');
        if ($at === false) {
            return null;
        }
        $host = strtolower(substr($emailAddress, $at + 1));

        return $host === '' ? null : $host;
    }

    private function ticketUrl(Environment $env, string $jwt): string
    {
        $base = Url::fapi($env, '');
        $query = http_build_query([
            '__authn_ticket' => $jwt,
            '__authn_status' => 'sign_up',
        ]);

        return rtrim($base, '/').'/sign-up?'.$query;
    }
}
