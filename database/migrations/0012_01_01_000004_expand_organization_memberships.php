<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `role_id` (FK to roles), public/private metadata bags. The legacy
 * `role` string column stays in place until AU-2 backfills `role_id` and
 * cuts the bootstrap over to the seeded roles. After that, AU-2 drops
 * the string column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->string('role_id', 64)->nullable()->after('user_id');
            $table->jsonb('public_metadata')->default(json_encode((object) []))->after('role');
            $table->jsonb('private_metadata')->default(json_encode((object) []))->after('public_metadata');

            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->index(['environment_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropIndex(['environment_id', 'role_id']);
            $table->dropColumn(['role_id', 'public_metadata', 'private_metadata']);
        });
    }
};
