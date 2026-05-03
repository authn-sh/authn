<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-up supporting schema (AU-10):
 *
 *   - environments.user_settings  jsonb attribute_settings shape (PLAN §13.2)
 *   - environments.signup_mode    'public' | 'restricted' (allowlist gates)
 *   - invitations                 BAPI-issued pre-verified invites; AU-13
 *                                 owns the CRUD surface
 *   - allowlist_identifiers       email / domain entries that are allowed in
 *   - blocklist_identifiers       email / domain entries that are blocked
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->jsonb('user_settings')->default(json_encode((object) []));
            $table->string('signup_mode', 16)->default('public');
        });

        Schema::create('invitations', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('email_address');
            $table->string('status', 16)->default('pending'); // pending|accepted|revoked|expired
            $table->jsonb('public_metadata')->default(json_encode((object) []));
            $table->string('redirect_url')->nullable();
            $table->string('redeemed_by_user_id', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('redeemed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['environment_id', 'email_address']);
        });

        Schema::create('allowlist_identifiers', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('identifier');                // foo@bar.com or @bar.com (domain)
            $table->string('identifier_type', 16);       // email_address | email_domain
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->unique(['environment_id', 'identifier']);
        });

        Schema::create('blocklist_identifiers', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('identifier');
            $table->string('identifier_type', 16);
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->unique(['environment_id', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocklist_identifiers');
        Schema::dropIfExists('allowlist_identifiers');
        Schema::dropIfExists('invitations');
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn(['user_settings', 'signup_mode']);
        });
    }
};
