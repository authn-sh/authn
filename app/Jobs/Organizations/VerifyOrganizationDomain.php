<?php

declare(strict_types=1);

namespace App\Jobs\Organizations;

use App\Events\Organizations\OrganizationDomainUpdated;
use App\Events\Organizations\OrganizationDomainVerified;
use App\Models\Challenge;
use App\Models\OrganizationDomain;
use App\Models\Verification;
use App\Services\Domains\DnsTxtResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls the `_authn-verification.<domain>` TXT record looking for the
 * Verification.nonce. On match: flip OrganizationDomain.verified=true +
 * Verification.status=verified and fire OrganizationDomainVerified.
 *
 * On miss: increment Verification.attempts and re-enqueue with a
 * capped backoff. Used both as the imperative trigger from
 * `POST /v1/organizations/{org}/domains/{dom}/verify` (via
 * `VerifyOrganizationDomain::dispatch`) and on a scheduled cron so a
 * verified domain whose TXT record disappears flips back to unverified.
 */
final class VerifyOrganizationDomain implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Attempts beyond this point stop the imperative retry loop. */
    public const MAX_ATTEMPTS = 24;

    /**
     * Backoff schedule (seconds) for the imperative path. Index = attempt#.
     * After ~24h we stop retrying and the cron re-checks.
     *
     * @var list<int>
     */
    public const BACKOFF_SCHEDULE = [
        60, 60, 120, 120, 300, 300, 600, 600, 900, 900,
        1800, 1800, 3600, 3600, 3600, 3600,
        7200, 7200, 14400, 14400, 14400, 14400, 14400, 14400,
    ];

    public int $tries = 1;

    public function __construct(public readonly string $domainId)
    {
        $this->onQueue('default');
    }

    public function handle(DnsTxtResolver $resolver): void
    {
        $domain = OrganizationDomain::query()->withoutGlobalScopes()->where('id', $this->domainId)->first();
        if ($domain === null) {
            return;
        }
        // Live pending Challenge is the primary source. For an already-
        // verified domain (recheck path), `current_challenge_id` is null
        // once we cleared it on the verified flip — fall back to the most
        // recent verified Challenge so we can read its nonce and demote on
        // a missing TXT.
        $challenge = $domain->current_challenge_id !== null
            ? Challenge::query()->withoutGlobalScopes()->where('id', $domain->current_challenge_id)->first()
            : Challenge::query()
                ->withoutGlobalScopes()
                ->where('parent_type', Challenge::PARENT_ORGANIZATION_DOMAIN)
                ->where('parent_id', $domain->id)
                ->where('strategy', Verification::STRATEGY_DOMAIN_DNS_TXT)
                ->where('status', Challenge::STATUS_VERIFIED)
                ->latest('id')
                ->first();
        if ($challenge === null) {
            Log::info('domain_verification_no_challenge', ['domain_id' => $domain->id]);

            return;
        }
        $verification = Verification::query()->withoutGlobalScopes()->where('id', $challenge->verification_id)->first();
        if ($verification === null) {
            return;
        }

        $expected = (string) $verification->nonce;
        if ($expected === '') {
            return;
        }

        $records = $resolver->resolve('_authn-verification.'.$domain->name);
        $matched = false;
        foreach ($records as $record) {
            if (str_contains($record, $expected)) {
                $matched = true;

                break;
            }
        }

        if ($matched) {
            $this->flipToVerified($domain, $challenge, $verification);

            return;
        }

        $this->recordMiss($domain, $challenge, $verification);
    }

    private function flipToVerified(OrganizationDomain $domain, Challenge $challenge, Verification $verification): void
    {
        $wasVerified = (bool) $domain->verified;
        $verification->forceFill([
            'status' => Verification::STATUS_VERIFIED,
            'verified_at' => now(),
        ])->save();
        $challenge->forceFill([
            'status' => Challenge::STATUS_VERIFIED,
            'attempts' => (int) $verification->attempts,
            'error_code' => null,
            'error_message' => null,
        ])->save();
        $domain->forceFill([
            'verified' => true,
            'current_challenge_id' => null,
        ])->save();

        if (! $wasVerified) {
            OrganizationDomainVerified::dispatch($domain->fresh());
        }
        OrganizationDomainUpdated::dispatch($domain->fresh());
    }

    private function recordMiss(OrganizationDomain $domain, Challenge $challenge, Verification $verification): void
    {
        $attempt = (int) $verification->attempts + 1;
        $verification->forceFill(['attempts' => $attempt])->save();
        $challenge->forceFill(['attempts' => $attempt])->save();

        // If the domain was previously verified and the TXT record is gone,
        // demote it. The org admin can issue a new Challenge to re-verify.
        if ($domain->verified) {
            $domain->forceFill(['verified' => false])->save();
            OrganizationDomainUpdated::dispatch($domain->fresh());
        }

        if ($attempt >= self::MAX_ATTEMPTS) {
            return;
        }

        $delay = self::BACKOFF_SCHEDULE[$attempt - 1] ?? end(self::BACKOFF_SCHEDULE);
        self::dispatch($domain->id)->delay(now()->addSeconds((int) $delay));
    }
}
