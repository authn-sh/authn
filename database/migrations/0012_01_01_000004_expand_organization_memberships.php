<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `role_id` (FK to roles) and the public/private metadata bags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->string('role_id', 64)->after('user_id');
            $table->jsonb('public_metadata')->default(json_encode((object) []))->after('role_id');
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
