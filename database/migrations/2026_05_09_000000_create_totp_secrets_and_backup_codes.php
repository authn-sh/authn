<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('totp_secrets', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('user_id', 64);
            $table->text('secret');
            $table->string('algorithm', 8)->default('SHA1');
            $table->unsignedSmallInteger('digits')->default(6);
            $table->unsignedSmallInteger('period_seconds')->default(30);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['environment_id', 'user_id']);
        });

        Schema::create('backup_codes', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('user_id', 64);
            $table->string('code_hash', 255);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['environment_id', 'user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_codes');
        Schema::dropIfExists('totp_secrets');
    }
};
