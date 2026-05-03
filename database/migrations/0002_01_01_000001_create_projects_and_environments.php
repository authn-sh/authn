<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects + Environments — the top of the tenancy spine.
 *
 * `_admin` (the reserved system project, `is_system = true`) gets created on
 * first boot by `php artisan authn:bootstrap`. Every other Project is owned
 * by an `Organization` (`owner_organization_id` FK) created via the
 * Dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('owner_organization_id', 64)->nullable()->index();
            $table->string('name');
            $table->string('slug', 64);
            $table->boolean('is_system')->default(false)->index();
            $table->timestamps();

            $table->unique(['owner_organization_id', 'slug']);
        });

        Schema::create('environments', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('project_id', 64);
            $table->string('kind', 32);
            $table->string('frontend_api_host')->unique();
            $table->string('dashboard_url')->nullable();
            $table->string('home_url')->nullable();
            $table->boolean('is_satellite')->default(false);
            $table->string('proxy_url')->nullable();
            $table->jsonb('allowed_origins')->default(json_encode([]));
            $table->jsonb('appearance')->default(json_encode((object) []));
            $table->jsonb('localization')->default(json_encode([
                'default_locale' => 'en-US',
                'supported_locales' => ['en-US'],
                'fallback_locale' => 'en-US',
                'overrides' => (object) [],
            ]));
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
        Schema::dropIfExists('projects');
    }
};
