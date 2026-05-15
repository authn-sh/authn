<?php

use App\Support\Id;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand `users` to the full v0.1 shape and split email storage out into
 * a dedicated `email_addresses` table. Also lays down the polymorphic
 * `verifications` + `verification_codes` tables that AU-9 / AU-10's
 * sign-in / sign-up flows hang their proof-of-ownership state on.
 *
 * The bootstrap `_admin` operator created in AU-2 still has its email
 * stored on `users.email`; this migration backfills that into a new
 * `email_addresses` row before dropping the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -------------------- email_addresses --------------------

        Schema::create('email_addresses', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('user_id', 64);
            $table->string('email_address');
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('linked_to_external_account_id', 64)->nullable();
            // Live Challenge (email_code) the SDK is currently polling.
            // Cleared once the Challenge leaves `pending`. FK is wired in
            // the back-fill pass below — challenges table doesn't exist
            // yet.
            $table->string('current_challenge_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['environment_id', 'email_address']);
            $table->index(['user_id']);
        });

        // -------------------- expand users --------------------

        Schema::table('users', function (Blueprint $table): void {
            $table->string('external_id')->nullable()->after('environment_id');
            $table->string('username')->nullable()->after('external_id');
            $table->string('image_url')->nullable()->after('last_name');
            $table->boolean('has_image')->default(false)->after('image_url');
            $table->string('primary_email_address_id', 64)->nullable()->after('has_image');
            $table->timestamp('password_changed_at')->nullable()->after('password_hash');
            $table->boolean('two_factor_enabled')->default(false);
            $table->boolean('totp_enabled')->default(false);
            $table->boolean('backup_code_enabled')->default(false);
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->timestamp('mfa_disabled_at')->nullable();
            $table->boolean('banned')->default(false);
            $table->boolean('locked')->default(false);
            $table->timestamp('lockout_expires_at')->nullable();
            $table->timestamp('last_sign_in_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->boolean('delete_self_enabled')->default(true);
            $table->jsonb('public_metadata')->default(json_encode((object) []));
            $table->jsonb('private_metadata')->default(json_encode((object) []));
            $table->jsonb('unsafe_metadata')->default(json_encode((object) []));
            $table->string('locale', 16)->nullable();
            $table->softDeletes();
        });

        // Backfill: any existing user (e.g. an _admin operator created by AU-2's
        // bootstrap) had its email stored on users.email. Lift that into a
        // verified email_addresses row, mark it primary, then drop the column.
        $rows = DB::table('users')->select('id', 'environment_id', 'email', 'email_verified_at', 'created_at', 'updated_at')->get();
        $now = now();
        foreach ($rows as $row) {
            if (empty($row->email)) {
                continue;
            }
            $emailId = Id::generate('eml_');
            DB::table('email_addresses')->insert([
                'id' => $emailId,
                'environment_id' => $row->environment_id,
                'user_id' => $row->id,
                'email_address' => strtolower((string) $row->email),
                'verified_at' => $row->email_verified_at,
                'is_primary' => true,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ]);
            DB::table('users')->where('id', $row->id)->update(['primary_email_address_id' => $emailId]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['environment_id', 'email']);
            $table->dropColumn('email');
            $table->dropColumn('email_verified_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['environment_id', 'external_id']);
            $table->unique(['environment_id', 'username']);
            $table->foreign('primary_email_address_id')->references('id')->on('email_addresses')->nullOnDelete();
        });

        // -------------------- verifications --------------------

        Schema::create('verifications', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);

            // Polymorphic owner (EmailAddress, SignInAttempt, SignUpAttempt, …).
            $table->string('verifiable_type', 64);
            $table->string('verifiable_id', 64);

            $table->string('strategy', 48);
            $table->string('status', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expire_at');

            $table->string('external_verification_redirect_url')->nullable();
            $table->string('nonce')->nullable();

            // Cross-device handoff plumbing (PLAN §9.10). Client model lands in AU-5;
            // these are stored as raw strings without a FK so AU-4 doesn't have to wait.
            $table->string('originating_client_id', 64)->nullable();
            $table->string('redeemed_by_client_id', 64)->nullable();
            $table->string('transfer_token')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->string('error_message')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->index(['verifiable_type', 'verifiable_id']);
            $table->index(['status', 'expire_at']);
        });

        // -------------------- verification_codes --------------------

        Schema::create('verification_codes', function (Blueprint $table): void {
            // Internal-only id — VerificationCode rows are pruned aggressively
            // and never surfaced to end users.
            $table->id();
            $table->string('verification_id', 64);
            $table->string('code_hash', 64);
            $table->string('purpose', 48);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('verification_id')->references('id')->on('verifications')->cascadeOnDelete();
            $table->index(['verification_id', 'purpose']);
            $table->index(['expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
        Schema::dropIfExists('verifications');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['primary_email_address_id']);
            $table->dropUnique(['environment_id', 'external_id']);
            $table->dropUnique(['environment_id', 'username']);
        });

        Schema::dropIfExists('email_addresses');

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->after('environment_id');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->unique(['environment_id', 'email']);

            $table->dropColumn([
                'external_id', 'username', 'image_url', 'has_image',
                'primary_email_address_id', 'password_changed_at',
                'two_factor_enabled', 'totp_enabled', 'backup_code_enabled',
                'mfa_enabled_at', 'mfa_disabled_at', 'banned', 'locked',
                'lockout_expires_at', 'last_sign_in_at', 'last_active_at',
                'delete_self_enabled', 'public_metadata', 'private_metadata',
                'unsafe_metadata', 'locale', 'deleted_at',
            ]);
        });
    }
};
