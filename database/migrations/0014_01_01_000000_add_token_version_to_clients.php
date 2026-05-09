<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `token_version` to `clients` so the cross-device magic-link flow
 * can invalidate the originating device's polled Client snapshot the
 * moment the click happens (PLAN §9.10 / AU-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedInteger('token_version')->default(0)->after('current_sign_up_attempt_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('token_version');
        });
    }
};
