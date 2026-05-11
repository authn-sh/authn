<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.6 SCIM 2.0 directory sync. `scim_tokens` stores rotating bearer rows
 * (one-time plaintext at create, hash on disk). `scim_attribute_mappings`
 * stores per-org overrides — defaults live in app/Scim/DefaultAttributeMappings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scim_tokens', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->binary('hashed_token');
            $table->string('prefix', 16);

            $table->string('organization_id', 64)->nullable();
            $table->string('enterprise_connection_id', 64)->nullable();
            $table->string('name');
            $table->string('created_by_user_id', 64);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('enterprise_connection_id')->references('id')->on('enterprise_connections')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique('hashed_token');
            $table->index(['organization_id', 'revoked_at']);
        });

        Schema::create('scim_attribute_mappings', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('organization_id', 64);
            $table->string('enterprise_connection_id', 64)->nullable();

            $table->string('source_attribute');
            $table->string('target_attribute');
            $table->text('transform')->nullable();

            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('enterprise_connection_id')->references('id')->on('enterprise_connections')->cascadeOnDelete();

            $table->unique(['organization_id', 'enterprise_connection_id', 'source_attribute'], 'scim_mappings_unique_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scim_attribute_mappings');
        Schema::dropIfExists('scim_tokens');
    }
};
