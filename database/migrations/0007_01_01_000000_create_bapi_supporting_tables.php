<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BAPI supporting schema (AU-13):
 *
 *   - redirect_urls               operator-managed allowlist of post-OAuth /
 *                                 magic-link redirect targets
 *   - users.password_imported     marks a row whose hash came from a migration
 *                                 import (verify_password still works, but the
 *                                 SDK should prompt a re-hash on next login)
 *   - users.password_hasher       which algorithm produced the imported hash
 *   - environments.support_email + appearance helpers via user_settings
 *     (no schema change — already a JSON blob)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirect_urls', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('url');
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->unique(['environment_id', 'url']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('password_imported')->default(false);
            $table->string('password_hasher', 32)->nullable();
        });

        Schema::table('invitations', function (Blueprint $table): void {
            $table->string('template_slug', 64)->default('invitation');
        });

        Schema::table('allowlist_identifiers', function (Blueprint $table): void {
            $table->boolean('notify')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('allowlist_identifiers', function (Blueprint $table): void {
            $table->dropColumn('notify');
        });
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropColumn('template_slug');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['password_imported', 'password_hasher']);
        });
        Schema::dropIfExists('redirect_urls');
    }
};
