<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.7 OAuth provider mode — registers third-party applications that
 * authenticate against authn.sh as the IdP, and tracks per-user consent
 * grants. `oauth_applications` holds the registered app (client_id +
 * hashed_client_secret + callback_urls[] + scopes[] + is_public).
 * `authorization_grants` holds the user's accepted consent for each
 * app, including the scopes granted at consent time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_applications', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('name');
            $table->string('client_id')->unique();
            $table->text('hashed_client_secret')->nullable();
            $table->jsonb('callback_urls')->default(json_encode([]));
            $table->jsonb('scopes')->default(json_encode([]));
            $table->boolean('is_public')->default(false);

            $table->timestamps();
            $table->softDeletes('removed_at');

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->index(['environment_id']);
        });

        Schema::create('authorization_grants', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('oauth_application_id', 64);
            $table->string('user_id', 64);
            $table->jsonb('scopes')->default(json_encode([]));
            $table->string('scopes_hash', 64);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('oauth_application_id')->references('id')->on('oauth_applications')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id']);
            $table->index(['oauth_application_id']);
        });

        // Partial unique index: at most one active (non-revoked) grant per
        // (user, app, scopes_hash). Revoked rows are intentionally kept for
        // audit purposes, so we filter them out of the uniqueness constraint.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX authorization_grants_active_unique ON authorization_grants (user_id, oauth_application_id, scopes_hash) WHERE revoked_at IS NULL');
        } else {
            Schema::table('authorization_grants', function (Blueprint $table): void {
                $table->unique(['user_id', 'oauth_application_id', 'scopes_hash'], 'authorization_grants_active_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_grants');
        Schema::dropIfExists('oauth_applications');
    }
};
