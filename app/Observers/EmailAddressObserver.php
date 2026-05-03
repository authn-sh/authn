<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EmailAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `email_addresses.is_primary` and `users.primary_email_address_id`
 * in sync. Whenever a row is saved with `is_primary = true`:
 *
 *   - Every other row on the same user is flipped to `is_primary = false`
 *     (only one primary per user at a time).
 *   - The user's `primary_email_address_id` column is updated to point
 *     at this row.
 *
 * Saving with `is_primary = false` is a no-op on the user row (we don't
 * blank `primary_email_address_id` on demote — the operator must promote
 * a different row to take over).
 */
final class EmailAddressObserver
{
    public function saved(EmailAddress $email): void
    {
        if (! $email->is_primary) {
            return;
        }

        DB::transaction(function () use ($email): void {
            EmailAddress::query()
                ->withoutGlobalScopes()
                ->where('user_id', $email->user_id)
                ->whereKeyNot($email->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            User::query()
                ->withoutGlobalScopes()
                ->whereKey($email->user_id)
                ->update(['primary_email_address_id' => $email->getKey()]);
        });
    }
}
