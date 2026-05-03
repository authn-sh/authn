<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the routing-time `slug` column to environments and drops the
 * uniqueness on `frontend_api_host`.
 *
 * - In subdomain mode, every env has a unique full host (`acme.authn.sh`)
 *   AND a slug (`acme`); both are derivable from each other.
 * - In path mode, every env shares the same `frontend_api_host`
 *   (`authn.sh`) — uniqueness moves to the slug, which is the path
 *   segment used to dispatch requests to FAPI.
 *
 * The slug is the global routing identity in both modes. It must be
 * unique across the whole installation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropUnique(['frontend_api_host']);
            $table->string('slug', 64)->after('kind');
        });

        Schema::table('environments', function (Blueprint $table): void {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
            $table->unique('frontend_api_host');
        });
    }
};
