<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.4 phone-number spine. Mirror of the v0.1 EmailAddress shape — one row
 * per phone, flat `verified_at` cache, `current_challenge_id` scoped via the
 * AU-9 phone_code Challenge dance.
 *
 * `email_addresses.linked_to_external_account_id` already lands in the v0.1
 * `0004_…_create_email_verifications` migration, so this one only adds
 * the new `phone_numbers` table + the matching `users` columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('user_id', 64);
            $table->string('phone_number');
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('reserved_for_second_factor')->default(false);
            $table->boolean('default_second_factor')->default(false);
            $table->string('linked_to_external_account_id', 64)->nullable();
            $table->string('current_challenge_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['environment_id', 'phone_number']);
            $table->index(['user_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('primary_phone_number_id', 64)->nullable()->after('primary_email_address_id');
            $table->boolean('phone_number_enabled')->default(false)->after('primary_phone_number_id');

            $table->foreign('primary_phone_number_id')->references('id')->on('phone_numbers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['primary_phone_number_id']);
            $table->dropColumn(['primary_phone_number_id', 'phone_number_enabled']);
        });

        Schema::dropIfExists('phone_numbers');
    }
};
