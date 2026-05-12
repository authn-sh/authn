<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.7 AU-4: track when each `JwtTemplate` was last used to render a
 * token. The BAPI delete endpoint refuses with 409 `jwt_template_in_use`
 * while `now - last_used_at < max(lifetime + allowed_clock_skew, 40h)`
 * — long-lived verifier caches may still be carrying tokens minted
 * under this name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jwt_templates', function (Blueprint $table): void {
            $table->timestamp('last_used_at')->nullable()->after('custom_signing_key');
        });
    }

    public function down(): void
    {
        Schema::table('jwt_templates', function (Blueprint $table): void {
            $table->dropColumn('last_used_at');
        });
    }
};
