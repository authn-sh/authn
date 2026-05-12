<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.7 JwtTemplate — per-environment named JWT templates with custom
 * claim mappings, lifetime, allowed_clock_skew, signing_algorithm and an
 * optional custom_signing_key escape hatch. Looked up by `name` from
 * Session::getToken({template}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jwt_templates', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('name');
            $table->jsonb('claims')->default(json_encode((object) []));
            $table->unsignedInteger('lifetime')->default(60);
            $table->unsignedInteger('allowed_clock_skew')->default(5);
            $table->string('signing_algorithm', 16)->default('RS256');
            $table->text('custom_signing_key')->nullable();

            $table->timestamps();
            $table->softDeletes('removed_at');

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->unique(['environment_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jwt_templates');
    }
};
