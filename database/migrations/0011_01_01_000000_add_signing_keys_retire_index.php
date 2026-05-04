<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AU-19 — RotateSigningKey scans by `(environment_id, status, retire_at)`
 * each tick. The (env, status) pair is already indexed; the retire_at
 * tail dimension lands here so the cron is index-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_keys', function (Blueprint $table): void {
            $table->index(['environment_id', 'status', 'retire_at'], 'signing_keys_env_status_retire_idx');
        });
    }

    public function down(): void
    {
        Schema::table('signing_keys', function (Blueprint $table): void {
            $table->dropIndex('signing_keys_env_status_retire_idx');
        });
    }
};
