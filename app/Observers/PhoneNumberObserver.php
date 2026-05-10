<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mirror of `EmailAddressObserver`. Whenever a PhoneNumber row is saved
 * with `is_primary = true`, every other row on the same user is demoted
 * and the user's `primary_phone_number_id` column is updated.
 *
 * `default_second_factor` is mutually exclusive across a user's verified
 * phones too — promote one, demote the rest. Demote is a no-op on
 * `primary_phone_number_id` (operator must promote a different row).
 */
final class PhoneNumberObserver
{
    public function saved(PhoneNumber $phone): void
    {
        if ($phone->is_primary) {
            DB::transaction(function () use ($phone): void {
                PhoneNumber::query()
                    ->withoutGlobalScopes()
                    ->where('user_id', $phone->user_id)
                    ->whereKeyNot($phone->getKey())
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);

                User::query()
                    ->withoutGlobalScopes()
                    ->whereKey($phone->user_id)
                    ->update(['primary_phone_number_id' => $phone->getKey()]);
            });
        }

        if ($phone->default_second_factor) {
            PhoneNumber::query()
                ->withoutGlobalScopes()
                ->where('user_id', $phone->user_id)
                ->whereKeyNot($phone->getKey())
                ->where('default_second_factor', true)
                ->update(['default_second_factor' => false]);
        }
    }
}
