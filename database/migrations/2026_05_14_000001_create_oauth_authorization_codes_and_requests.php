<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.7 OAuth provider mode — short-lived authorization codes + parked
 * authorize-request contexts.
 *
 * `oauth_authorization_codes` rows are minted by GET /oauth/authorize when
 * the user already has a covering AuthorizationGrant (silent reuse) or by
 * POST /oauth/consent when the user accepts (AU-9). They live for 5 minutes
 * and are exchanged by POST /oauth/token (AU-7).
 *
 * `oauth_request_contexts` rows park the original /oauth/authorize query
 * parameters during the user-facing consent flow so the Account Portal can
 * reconstruct them without a long opaque URL. 10-minute TTL per spec OA-3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_authorization_codes', function (Blueprint $table): void {
            $table->string('code', 96)->primary();
            $table->string('environment_id', 64);
            $table->string('oauth_application_id', 64);
            $table->string('user_id', 64);
            $table->jsonb('scopes')->default(json_encode([]));
            $table->string('redirect_uri', 2048);
            $table->text('code_challenge')->nullable();
            $table->string('code_challenge_method', 16)->nullable();
            $table->text('nonce')->nullable();
            $table->string('state', 2048)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('oauth_application_id')->references('id')->on('oauth_applications')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['expires_at']);
            $table->index(['oauth_application_id']);
        });

        Schema::create('oauth_request_contexts', function (Blueprint $table): void {
            $table->string('request_id', 96)->primary();
            $table->string('environment_id', 64);
            $table->string('oauth_application_id', 64);
            $table->jsonb('params');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('oauth_application_id')->references('id')->on('oauth_applications')->cascadeOnDelete();
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_request_contexts');
        Schema::dropIfExists('oauth_authorization_codes');
    }
};
