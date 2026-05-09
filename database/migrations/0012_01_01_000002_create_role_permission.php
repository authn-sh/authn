<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission', function (Blueprint $table): void {
            $table->string('role_id', 64);
            $table->string('permission_id', 64);

            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->index('permission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
    }
};
