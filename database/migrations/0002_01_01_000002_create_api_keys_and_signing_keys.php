<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-environment credentials.
 *
 * - `api_keys` stores the SHA-256 hash of every issued `sk_…` / `pk_…` key.
 *   The plaintext is shown once on creation (or when rotated) and never again.
 * - `signing_keys` stores per-environment RS256 keypairs whose public halves
 *   are advertised at the env's `/.well-known/jwks.json`. Private PEM is
 *   encrypted with Laravel's app key (the secrets-driver abstraction in
 *   PLAN §17.6 lands later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('kind', 32);
            $table->string('prefix', 64);
            $table->string('hashed_secret', 64)->unique();
            $table->string('name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_by_user_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->index(['environment_id', 'kind']);
        });

        Schema::create('signing_keys', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('algorithm', 16)->default('RS256');
            $table->jsonb('public_jwk');
            $table->text('encrypted_private_pem');
            $table->string('status', 32);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retire_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->index(['environment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_keys');
        Schema::dropIfExists('api_keys');
    }
};
