<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Jobs\Organizations\VerifyOrganizationDomain;
use App\Models\OrganizationDomain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Daily fan-out: re-check every verified OrganizationDomain so a TXT
 * record that disappears flips back to `verified=false`. AU-13's
 * dashboard surfaces the demoted state next to a "verify again" button.
 */
final class RecheckVerifiedDomains implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        OrganizationDomain::query()
            ->withoutGlobalScopes()
            ->where('verified', true)
            ->orderBy('id')
            ->chunk(200, function ($domains): void {
                foreach ($domains as $domain) {
                    VerifyOrganizationDomain::dispatch($domain->id);
                }
            });
    }
}
