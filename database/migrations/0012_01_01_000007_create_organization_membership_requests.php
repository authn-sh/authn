<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_membership_requests', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('organization_id', 64);
            $table->string('user_id', 64);
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_membership_requests');
    }
};
