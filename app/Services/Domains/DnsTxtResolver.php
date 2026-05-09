<?php

declare(strict_types=1);

namespace App\Services\Domains;

/**
 * Indirection over the system DNS resolver so AU-9's verification job can
 * be unit-tested without hitting the wider internet. The default
 * implementation uses PHP's `dns_get_record` and is rebound with a fake
 * in tests via `app()->instance(DnsTxtResolver::class, …)`.
 */
class DnsTxtResolver
{
    /**
     * Returns every TXT record value under `<host>`. Each TXT record's
     * fragment list is concatenated (per the DNS strings-as-rope rule).
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }
        $out = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            if (isset($record['txt']) && is_string($record['txt'])) {
                $out[] = $record['txt'];

                continue;
            }
            if (isset($record['entries']) && is_array($record['entries'])) {
                $out[] = implode('', array_filter($record['entries'], 'is_string'));
            }
        }

        return $out;
    }
}
