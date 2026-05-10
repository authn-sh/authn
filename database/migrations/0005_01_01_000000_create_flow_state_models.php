<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The auth-flow state plumbing every FAPI surface (AU-8 onward) hangs on:
 *
 *   - clients              — device-scoped state container (one per browser
 *                            install; identified by the `__client` cookie)
 *   - sign_in_attempts     — in-progress sign-in state machines
 *   - sign_up_attempts     — in-progress sign-up state machines
 *   - challenges           — uniform verification sub-resource attached to
 *                            sign_in_attempts / sign_up_attempts
 *   - sessions             — live authenticated sessions
 *   - session_activities   — append-only touch log per session
 *
 * Cross-table FKs are wired here in dependency order; the loop-back FKs
 * from clients → sessions / attempts are added in a second Schema::table
 * pass once both sides exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------- clients

        Schema::create('clients', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);

            // 32-byte random secret. The HttpOnly `__client` cookie carries
            // `<client_id>.<base64url(hmac_sha256(secret, client_id))>`; the
            // server recomputes and constant-time-compares on every request.
            $table->string('cookie_secret', 64);

            $table->string('last_active_session_id', 64)->nullable();
            $table->string('current_sign_in_attempt_id', 64)->nullable();
            $table->string('current_sign_up_attempt_id', 64)->nullable();

            $table->jsonb('device_fingerprint')->default(json_encode((object) []));

            $table->timestamps();
            $table->timestamp('last_active_at')->nullable();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
        });

        // ----------------------------------------------------- sign_in_attempts

        Schema::create('sign_in_attempts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('client_id', 64);

            $table->string('status', 32);
            $table->string('identifier')->nullable();

            $table->string('current_challenge_id', 64)->nullable();

            $table->string('created_session_id', 64)->nullable();

            $table->timestamp('abandon_at');
            $table->string('transfer_token')->nullable();

            $table->boolean('was_test')->default(false);

            $table->string('captcha_token')->nullable();
            $table->string('captcha_widget_type', 32)->nullable();
            $table->string('captcha_error')->nullable();

            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->index(['status', 'abandon_at']);
        });

        // ----------------------------------------------------- sign_up_attempts

        Schema::create('sign_up_attempts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('client_id', 64);

            $table->string('status', 32);

            // Staged identity fields. Persisted before the User row exists so
            // the SDK can re-render in-progress sign-ups across page loads.
            $table->string('email_address')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('password_hash')->nullable();

            $table->jsonb('unsafe_metadata')->default(json_encode((object) []));
            $table->jsonb('public_metadata')->default(json_encode((object) []));
            $table->jsonb('missing_fields')->default(json_encode([]));
            $table->jsonb('unverified_fields')->default(json_encode([]));

            $table->string('current_challenge_id', 64)->nullable();

            $table->string('created_session_id', 64)->nullable();
            $table->string('created_user_id', 64)->nullable();

            $table->timestamp('abandon_at');
            $table->string('transfer_token')->nullable();

            $table->boolean('was_test')->default(false);

            $table->string('captcha_token')->nullable();
            $table->string('captcha_widget_type', 32)->nullable();
            $table->string('captcha_error')->nullable();

            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('created_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'abandon_at']);
        });

        // ----------------------------------------------------- challenges

        Schema::create('challenges', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);

            // Polymorphic parent: 'sign_in' | 'sign_up' | 'email_address' |
            // 'organization_domain'. The parent_id points at the matching
            // table; no DB-level FK because the type column disambiguates.
            $table->string('parent_type', 32);
            $table->string('parent_id', 64);

            $table->string('step', 16);
            $table->string('strategy', 64);
            $table->string('status', 32);

            $table->string('verification_id', 64);
            $table->unsignedInteger('attempts')->default(0);

            $table->string('nonce')->nullable();
            $table->string('external_verification_redirect_url', 2048)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message')->nullable();

            $table->timestamp('expire_at');
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('verification_id')->references('id')->on('verifications')->cascadeOnDelete();
            $table->index(['parent_type', 'parent_id', 'status']);
        });

        Schema::table('sign_in_attempts', function (Blueprint $table): void {
            $table->foreign('current_challenge_id')->references('id')->on('challenges')->nullOnDelete();
        });

        Schema::table('sign_up_attempts', function (Blueprint $table): void {
            $table->foreign('current_challenge_id')->references('id')->on('challenges')->nullOnDelete();
        });

        Schema::table('email_addresses', function (Blueprint $table): void {
            $table->foreign('current_challenge_id')->references('id')->on('challenges')->nullOnDelete();
        });

        // ----------------------------------------------------- sessions

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('client_id', 64);
            $table->string('user_id', 64);

            $table->string('status', 32);

            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('expire_at');
            $table->timestamp('abandon_at')->nullable();

            $table->string('last_active_organization_id', 64)->nullable();

            // Impersonation marker: {iss, sub, sid} of the actor session.
            $table->jsonb('actor')->nullable();

            $table->boolean('was_test')->default(false);

            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['client_id', 'status']);
            $table->index(['expire_at', 'status']);
        });

        // ----------------------------------------------------- session_activities

        Schema::create('session_activities', function (Blueprint $table): void {
            // Append-only log; bigint id is fine — never returned by external API.
            $table->id();
            $table->string('session_id', 64);

            $table->string('device_type', 32)->nullable();
            $table->boolean('is_mobile')->nullable();
            $table->string('browser_name', 64)->nullable();
            $table->string('browser_version', 32)->nullable();
            $table->string('os_name', 64)->nullable();
            $table->string('ip_address', 45)->nullable(); // INET / IPv6 fits 45
            $table->string('city', 128)->nullable();
            $table->string('country', 2)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('session_id')->references('id')->on('sessions')->cascadeOnDelete();
            $table->index(['session_id', 'created_at']);
        });

        // ----------------------------------------------------- back-fill loop FKs

        Schema::table('clients', function (Blueprint $table): void {
            $table->foreign('last_active_session_id')->references('id')->on('sessions')->nullOnDelete();
            $table->foreign('current_sign_in_attempt_id')->references('id')->on('sign_in_attempts')->nullOnDelete();
            $table->foreign('current_sign_up_attempt_id')->references('id')->on('sign_up_attempts')->nullOnDelete();
        });

        Schema::table('sign_in_attempts', function (Blueprint $table): void {
            $table->foreign('created_session_id')->references('id')->on('sessions')->nullOnDelete();
        });

        Schema::table('sign_up_attempts', function (Blueprint $table): void {
            $table->foreign('created_session_id')->references('id')->on('sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_addresses', function (Blueprint $table): void {
            $table->dropForeign(['current_challenge_id']);
        });
        Schema::table('sign_up_attempts', function (Blueprint $table): void {
            $table->dropForeign(['created_session_id']);
            $table->dropForeign(['current_challenge_id']);
        });
        Schema::table('sign_in_attempts', function (Blueprint $table): void {
            $table->dropForeign(['created_session_id']);
            $table->dropForeign(['current_challenge_id']);
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropForeign(['last_active_session_id']);
            $table->dropForeign(['current_sign_in_attempt_id']);
            $table->dropForeign(['current_sign_up_attempt_id']);
        });

        Schema::dropIfExists('session_activities');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('challenges');
        Schema::dropIfExists('sign_up_attempts');
        Schema::dropIfExists('sign_in_attempts');
        Schema::dropIfExists('clients');
    }
};
