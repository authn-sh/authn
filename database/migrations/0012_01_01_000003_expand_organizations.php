<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lift the v0.1 stub organizations table to its full v0.2 surface.
 *
 * The v0.1 cut shipped with just (id, environment_id, name, slug,
 * created_by_user_id) — enough for the bootstrap workspace. v0.2 adds
 * branding, counter caches, the membership cap, the admin-delete flag,
 * and the public/private metadata bags every other resource carries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('slug');
            $table->unsignedInteger('members_count')->default(0)->after('image_path');
            $table->unsignedInteger('pending_invitations_count')->default(0)->after('members_count');
            $table->unsignedInteger('max_allowed_memberships')->nullable()->after('pending_invitations_count');
            $table->boolean('admin_delete_enabled')->default(true)->after('max_allowed_memberships');
            $table->jsonb('public_metadata')->default(json_encode((object) []))->after('admin_delete_enabled');
            $table->jsonb('private_metadata')->default(json_encode((object) []))->after('public_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'image_path',
                'members_count',
                'pending_invitations_count',
                'max_allowed_memberships',
                'admin_delete_enabled',
                'public_metadata',
                'private_metadata',
            ]);
        });
    }
};
