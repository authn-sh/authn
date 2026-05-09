<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org-scoped invitations. The single-use ticket carried on the magic-link
 * URL is materialised as a `verification_codes` row (`purpose=invitation_ticket`,
 * see PLAN §4 / §9.7) — `ticket_verification_code_id` points at it.
 *
 * Distinct from the env-scoped `invitations` table that AU-10 sign-up uses;
 * these are issued by an org admin to add someone to a specific org.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('organization_id', 64);
            $table->string('email_address');
            $table->string('role_id', 64);
            $table->string('inviter_user_id', 64)->nullable();
            $table->string('redirect_url')->nullable();
            $table->string('status', 16)->default('pending');
            $table->jsonb('public_metadata')->default(json_encode((object) []));
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('ticket_verification_code_id')->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('inviter_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('ticket_verification_code_id')->references('id')->on('verification_codes')->nullOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['environment_id', 'email_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
