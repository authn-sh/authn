<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64);

            // Raw WebAuthn credential id (binary blob). Encrypted at rest via
            // Laravel's `encrypted` cast on the model. SQLite-friendly `binary`
            // maps to BLOB; PostgreSQL maps to BYTEA.
            $table->binary('credential_id');

            // SHA-256 of credential_id. Indexed for fast uniqueness checks and
            // sign-in lookups without decrypting the full credential blob.
            $table->binary('credential_id_hash');

            // COSE public key blob (encrypted at rest via the model cast).
            $table->text('public_key');

            $table->unsignedBigInteger('sign_count')->default(0);
            $table->jsonb('transports')->default(json_encode([]));
            $table->uuid('aaguid')->nullable();
            $table->string('nickname')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes('removed_at');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
            $table->index(['user_id', 'verified_at']);
            $table->unique('credential_id_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
