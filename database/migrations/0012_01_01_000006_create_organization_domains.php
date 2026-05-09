<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_domains', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('organization_id', 64);
            $table->string('name');
            $table->boolean('verified')->default(false);
            $table->string('enrollment_mode', 32)->default('manual_invitation');
            $table->string('verification_id', 64)->nullable();
            $table->string('affiliation_email_address')->nullable();
            $table->unsignedInteger('total_pending_invitations')->default(0);
            $table->unsignedInteger('total_pending_suggestions')->default(0);
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('verification_id')->references('id')->on('verifications')->nullOnDelete();

            $table->unique(['environment_id', 'name']);
            $table->index(['organization_id', 'verified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_domains');
    }
};
