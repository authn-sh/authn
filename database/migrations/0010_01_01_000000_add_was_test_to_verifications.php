<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AU-18 — verifications.was_test marker so the dispatcher / dashboard can
 * filter activity that originated from a reserved test identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verifications', function (Blueprint $table): void {
            $table->boolean('was_test')->default(false)->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('verifications', function (Blueprint $table): void {
            $table->dropColumn('was_test');
        });
    }
};
