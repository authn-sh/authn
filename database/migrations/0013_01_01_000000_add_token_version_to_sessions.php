<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `token_version` counter so the session-token issuer can
 * invalidate any in-flight token cache the SDK keeps after the operator
 * switches active organization (or any other claim-affecting state).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->unsignedInteger('token_version')->default(0)->after('last_active_organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropColumn('token_version');
        });
    }
};
