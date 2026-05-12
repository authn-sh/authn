<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.7 OAuth provider mode — refresh tokens. Rotated on every
 * `/oauth/token grant_type=refresh_token` exchange; the previous row
 * gets its `revoked_at` stamped in the same transaction.
 *
 * Storage: opaque random secret hashed via sha256 (Argon2id is overkill
 * for short-lived rotating credentials; the rotation discipline is the
 * real defense). 30-day lifetime per spec OA-4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_refresh_tokens', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('oauth_application_id', 64);
            $table->string('user_id', 64);
            $table->string('hashed_token', 64); // sha256 hex
            $table->jsonb('scopes')->default(json_encode([]));
            $table->text('nonce')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('rotated_from_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('oauth_application_id')->references('id')->on('oauth_applications')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['hashed_token']);
            $table->index(['oauth_application_id', 'user_id']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
    }
};
