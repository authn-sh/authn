<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal-viable end-user and organization tables — just enough for the
 * `authn:bootstrap` flow to provision the first operator and their workspace.
 *
 * - `users` will be expanded by AU-4 with the full v0.1 column set
 *   (external_id, image_url, MFA flags, lockout, metadata, …) plus a separate
 *   `email_addresses` table.
 * - `organizations` and `organization_memberships` are stubs; the full v0.2
 *   shape (domains, invitations, custom roles, permissions) lands then.
 *
 * For v0.1, every `User` and `Organization` is scoped to one `Environment`;
 * the global EnvironmentScope that enforces this lands in AU-4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->unique(['environment_id', 'email']);
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('name');
            $table->string('slug', 64);
            $table->string('created_by_user_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['environment_id', 'slug']);
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('organization_id', 64);
            $table->string('user_id', 64);
            $table->string('role', 64);
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['environment_id', 'role']);
        });

        // Now that users exists, wire the FK from projects.owner_organization_id → organizations.id
        // (couldn't do it in 000001 because organizations didn't exist yet).
        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('owner_organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        // And api_keys.created_by_user_id → users.id.
        Schema::table('api_keys', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['owner_organization_id']);
        });

        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('users');
    }
};
